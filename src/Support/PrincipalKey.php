<?php

namespace SchoolAid\Assistant\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * La identidad de quien habla con el asistente: `{tabla}:{id}` (`users:5`, `families:5`).
 * Dos principales de clases distintas pueden compartir id, así que el id solo no los distingue.
 */
final class PrincipalKey
{
    /**
     * Un modelo da `{getTable()}:{id}`; un principal que no es modelo, `principal:{id}`.
     */
    public static function of(Authenticatable $principal): string
    {
        if ($principal instanceof Model) {
            return $principal->getTable().':'.$principal->getAuthIdentifier();
        }

        return 'principal:'.$principal->getAuthIdentifier();
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function forModel(string $modelClass, int|string $id): string
    {
        return (new $modelClass)->getTable().':'.$id;
    }
}
