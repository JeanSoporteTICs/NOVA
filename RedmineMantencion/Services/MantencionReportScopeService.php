<?php

namespace App\Modulos\RedmineMantencion\Services;

final class MantencionReportScopeService
{
    /**
     * Mantencion intentionally exposes only reports assigned to the connected
     * user. This preserves the procedural dashboard rule for every native
     * read and mutation.
     *
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array<string,mixed>  $context
     * @return array<int,array<string,mixed>>
     */
    public function visible(array $messages, array $context): array
    {
        return array_values(array_filter(
            $messages,
            fn (mixed $message): bool => is_array($message) && $this->canAccess($message, $context),
        ));
    }

    /** @param array<string,mixed> $message @param array<string,mixed> $context */
    public function canAccess(array $message, array $context): bool
    {
        $viewerId = trim((string) ($context['viewer_id'] ?? ''));
        $assignedId = trim((string) ($message['asignado_a'] ?? $message['id_redmine_asignado'] ?? ''));
        if ($viewerId !== '' && $assignedId !== '' && $viewerId === $assignedId) {
            return true;
        }

        $assignedName = trim((string) ($message['asignado_nombre'] ?? $message['core_usuario_asignado'] ?? ''));
        $viewerName = trim((string) ($context['viewer_name'] ?? ''));
        if ($assignedName === '' || $viewerName === '') {
            return false;
        }

        $assignedTokens = $this->tokens($assignedName);
        $viewerTokens = $this->tokens($viewerName);

        return count($viewerTokens) >= 2 && array_diff($viewerTokens, $assignedTokens) === [];
    }

    /** @param array<int,string> $ids @param array<int,array<string,mixed>> $messages @param array<string,mixed> $context @return array<int,string> */
    public function allowedIds(array $ids, array $messages, array $context): array
    {
        $allowed = [];
        foreach ($this->visible($messages, $context) as $message) {
            $id = trim((string) ($message['id'] ?? ''));
            if ($id !== '') {
                $allowed[$id] = true;
            }
        }

        return array_values(array_filter(array_unique(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $ids,
        )), static fn (string $id): bool => isset($allowed[$id])));
    }

    /** @return array<int,string> */
    private function tokens(string $value): array
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', is_string($ascii) ? $ascii : $value) ?? '';

        return array_values(array_filter(explode(' ', trim($normalized))));
    }
}
