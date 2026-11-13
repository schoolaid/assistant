<?php

namespace SchoolAid\Assistant\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * El modelo de los usuarios del panel de la app: `assistant.user_model` o, sin él, el del
 * provider `users` de la autenticación.
 */
class PanelUsers
{
    /**
     * @return class-string<Model>
     */
    public static function model(): string
    {
        return (string) AssistantNaming::config('user_model', config('auth.providers.users.model'));
    }

    /**
     * @return Builder<Model>
     */
    public static function query(): Builder
    {
        return self::model()::query();
    }
}
