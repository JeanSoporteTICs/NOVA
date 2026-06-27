<?php

namespace App\Modulos\Emach\Repositories;

use App\Modulos\Nova\Repositories\UserIntegrationRepository;

/**
 * Thin wrapper around UserIntegrationRepository for EMACH credentials.
 *
 * EMACH credentials (username + password) are stored in the shared
 * integrations_usuario table via UserIntegrationRepository with type 'emach'.
 * This class exposes a module-specific API so EmachController does not
 * depend directly on the shared repository.
 */
final class EmachCredentialsRepository
{
    public function __construct(private readonly UserIntegrationRepository $integrations)
    {
    }

    /**
     * @param array<string,mixed> $sessionUser
     * @return array{external_user: string, has_secret: bool}
     */
    public function forUser(array $sessionUser): array
    {
        return $this->integrations->integrationForSession($sessionUser, 'emach');
    }
}
