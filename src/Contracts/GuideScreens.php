<?php

namespace SchoolAid\Assistant\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Las pantallas del panel de las que habla una página de la guía, como las ve una persona. El eval de conocimiento las
 * usa para pedir que la respuesta enlace la pantalla y para marcar las rutas inventadas.
 */
interface GuideScreens
{
    /**
     * Las pantallas de la página que la persona ve, en el orden de la página.
     *
     * @return list<array{label: string, url: string}>
     */
    public function forPage(string $page, Authenticatable $user): array;

    /**
     * Las URLs de todas las pantallas que la persona ve, con la misma raíz que {@see self::forPage()}.
     *
     * @return list<string>
     */
    public function visible(Authenticatable $user): array;
}
