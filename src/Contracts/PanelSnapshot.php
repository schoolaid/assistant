<?php

namespace SchoolAid\Assistant\Contracts;

/**
 * Lo que el panel sabía de la persona al enviar el mensaje: quién es y con qué alcance trabajaba
 * (en MarketAid, su selección de sucursales). Se guarda en el turno con {@see self::toArray()} y
 * {@see PanelScope::restore()} lo recupera para que el Job y el Confirmar corran las tools igual.
 */
interface PanelSnapshot
{
    public function userId(): int;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
