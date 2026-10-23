<?php

namespace App\Repositories;

/**
 * Les listes du dashboard admin joignent la table des droits : une même
 * entité (stream ou évènement) remonte donc une fois par admin rattaché.
 * Cette classe replie ces lignes en une seule par entité et concatène les
 * emails dans la colonne `admin`.
 */
final class AdminListMerger
{
    /**
     * @param object[] $rows Lignes avec `id` et `admin` (email ou null), l'ordre des admins d'une entité est conservé.
     * @return object[] Une ligne par `id`, dans l'ordre de première apparition.
     */
    public static function mergeByEntity(array $rows): array
    {
        $merged = [];
        $emails = [];

        foreach ($rows as $row) {
            $id = $row->id;
            if (!isset($merged[$id])) {
                $merged[$id] = $row;
                $emails[$id] = [];
            }
            if ($row->admin !== null && !in_array($row->admin, $emails[$id], true)) {
                $emails[$id][] = $row->admin;
            }
        }

        foreach ($merged as $id => $row) {
            $row->admin = $emails[$id] ? implode(', ', $emails[$id]) : null;
        }

        return array_values($merged);
    }
}
