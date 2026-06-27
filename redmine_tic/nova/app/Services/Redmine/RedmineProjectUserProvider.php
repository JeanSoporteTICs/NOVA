<?php

namespace RedmineTic\Services\Redmine;

use App\Contracts\ProjectUserProviderInterface;
use RedmineTic\Support\Redmine\RedmineDataRepository;

final class RedmineProjectUserProvider implements ProjectUserProviderInterface
{
    private const SUPPORTED_PROJECT = 'redmine_tic';

    public function __construct(private RedmineDataRepository $repository)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function projectUsers(string $projectKey): array
    {
        if ($projectKey !== self::SUPPORTED_PROJECT) {
            return [];
        }

        return $this->repository->forProject($projectKey)->users();
    }
}
