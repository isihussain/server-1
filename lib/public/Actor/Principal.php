<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCP\Actor;

use InvalidArgumentException;
use JsonSerializable;
use OCP\App\IAppManager;
use OCP\AppFramework\Attribute\Consumable;
use Override;

/**
 * A principal defines the identity of an actor.
 * It specifies who the actor is and where that identity lives.
 *
 * @psalm-immutable
 * @since 36.0.0
 */
#[Consumable(since: '36.0.0')]
final readonly class Principal implements JsonSerializable {
	/**
	 * @param non-empty-string $id - user id, anonymous user id, bot id or app id of a system component
	 * @param ?non-empty-string $origin - remote instance for federated users, scope of anonymous users, null otherwise
	 * @param ?non-empty-string $source - app-namespaced kind of anonymous user, e.g. "spreed:guest" or "files_sharing:visitor";
	 *                                  optionally the app owning a bot, e.g. "spreed", or the system component name; null for users
	 */
	private function __construct(
		public PrincipalType $type,
		public string $id,
		public ?string $origin = null,
		public ?string $source = null,
	) {
		$originAllowed = match ($type) {
			PrincipalType::Anonymous, PrincipalType::User => $origin === null || $origin !== '',
			PrincipalType::Bot, PrincipalType::System => $origin === null,
			default => false,
		};
		if (!$originAllowed) {
			throw new InvalidArgumentException(sprintf(
				'Invalid origin for principal of type "%s"',
				$type->value,
			));
		}

		$sourceAllowed = match ($type) {
			PrincipalType::Anonymous => $source !== null && $source !== '',
			PrincipalType::Bot, PrincipalType::System => $source === null || $source !== '',
			PrincipalType::User => $source === null,
			default => false,
		};
		if (!$sourceAllowed) {
			throw new InvalidArgumentException(sprintf(
				'Invalid source for principal of type "%s"',
				$type->value,
			));
		}
	}

	/**
	 * Identifies a local or federated user.
	 *
	 * @param non-empty-string $userId - user id
	 * @param ?non-empty-string $remote - remote instance for federated users, null for local users
	 * @since 36.0.0
	 */
	public static function user(string $userId, ?string $remote = null): self {
		return new self(PrincipalType::User, $userId, $remote);
	}

	/**
	 * Identifies an anonymous user, e.g. a public visitor of a public share.
	 *
	 * @param non-empty-string $externalId - anonymous user id (e.g. the nickname set by the public visitor)
	 * @param non-empty-string $scope - the scope the anonymous id is unique within, e.g. a share token, a Talk room token or a form hash
	 * @param ?non-empty-string $source app-namespaced kind of external user, e.g. `files_sharing:visitor` or `spreed:guest`
	 * @since 36.0.0
	 */
	public static function anonymous(string $externalId, string $scope, ?string $source = null): self {
		if ($source !== null) {
			$matches = [];
			preg_match('/^([^:]+):.+$/', $source, $matches) ?: throw new InvalidArgumentException(sprintf('Invalid source "%s"', $source));
			self::validateAppId($matches[1]);
		}
		return new self(PrincipalType::Anonymous, $externalId, $scope, $source);
	}

	/**
	 * @param non-empty-string $botId - bot id
	 * @param ?non-empty-string $source - app owning the bot, e.g. "spreed"
	 * @since 36.0.0
	 */
	public static function bot(string $botId, ?string $source = null): self {
		if ($source !== null) {
			self::validateAppId($source);
		}
		return new self(PrincipalType::Bot, $botId, null, $source);
	}

	/**
	 * Identifies a system component
	 *
	 * e.g. "retention" component of the "files_versions" app would be represented as `Principal::system('files_versions', 'retention')`
	 *
	 * @param non-empty-string $appId - app id (e.g. "files_versions")
	 * @param ?non-empty-string $source - component name (e.g. "retention" for the retention component of the "files_versions" app)
	 * @since 36.0.0
	 */
	public static function system(string $appId, ?string $source = null): self {
		self::validateAppId($appId);
		return new self(PrincipalType::System, $appId, null, $source);
	}

	/**
	 * @since 36.0.0
	 */
	public function is(PrincipalType $type): bool {
		return $this->type === $type;
	}

	/**
	 * @since 36.0.0
	 */
	public function equals(self $other): bool {
		return $this->type === $other->type
			&& $this->id === $other->id
			&& $this->origin === $other->origin
			&& $this->source === $other->source;
	}

	/**
	 * Stable key for logs, cache keys and cycle detection
	 *
	 * e.g. a federated user: `user|bob@cloud.example`
	 * or an anonymous user on a public share: `anonymous|nickname@sharetoken|files_sharing:visitor`
	 *
	 * @since 36.0.0
	 */
	public function key(): string {
		return $this->type->value . '|'
			. $this->id
			. ($this->origin !== null ? '@' . $this->origin : '')
			. ($this->source !== null ? '|' . $this->source : '');
	}

	/**
	 * @return array{type: non-empty-string, id: non-empty-string, origin?: ?non-empty-string, source?: ?non-empty-string}
	 * @since 36.0.0
	 */
	#[Override]
	public function jsonSerialize(): array {
		return [
			'type' => $this->type->value,
			'id' => $this->id,
			'origin' => $this->origin,
			'source' => $this->source,
		];
	}

	/**
	 * @param array<string, mixed> $data - see `Principal::jsonSerialize`
	 * @throws InvalidArgumentException if the data is invalid or the type is unknown
	 * @since 36.0.0
	 */
	public static function fromArray(array $data): self {
		self::assertValidData($data);
		$type = $data['type'];
		$id = $data['id'];
		$origin = $data['origin'] ?? null;
		$source = $data['source'] ?? null;

		return new self(
			PrincipalType::tryFrom($type) ?? throw new InvalidArgumentException(sprintf('Unknown principal type "%s"', $type)),
			$id,
			$origin,
			$source,
		);
	}

	/**
	 * @psalm-assert array{type: non-empty-string, id: non-empty-string, origin?: ?non-empty-string, source?: ?non-empty-string} $data
	 * @throws InvalidArgumentException - if the data is invalid
	 */
	private static function assertValidData(array $data): void {
		$type = $data['type'] ?? null;
		$id = $data['id'] ?? null;
		$origin = $data['origin'] ?? null;
		$source = $data['source'] ?? null;
		if (!is_string($type) || $type === ''
			|| !is_string($id) || $id === ''
			|| ($origin !== null && !is_string($origin)) || $origin === ''
			|| ($source !== null && !is_string($source)) || $source === '') {
			throw new InvalidArgumentException('Invalid principal data');
		}
	}

	/**
	 * Verify the given app id is valid
	 * @param string $appId - app id to validate
	 * @throws InvalidArgumentException - If the app id is invalid
	 */
	private static function validateAppId(string $appId): void {
		$appManager = \OCP\Server::get(IAppManager::class);
		if ($appId === '' || $appId !== $appManager->cleanAppId($appId) || !$appManager->isEnabledForAnyone($appId)) {
			throw new InvalidArgumentException(sprintf('Invalid app id "%s"', $appId));
		}
	}
}
