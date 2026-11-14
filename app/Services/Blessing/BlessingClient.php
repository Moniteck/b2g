<?php

namespace App\Services\Blessing;

/**
 * Conector con el sistema de inventario Blessing.
 *
 * Implementaciones:
 *  - HttpBlessingClient: la API real (mapeo de campos PENDIENTE de la documentación).
 *  - NullBlessingClient: la empresa no tiene conexión configurada.
 *  - FakeBlessingClient: catálogo de demostración, solo en local y pruebas.
 *
 * Conectar la API real es completar HttpBlessingClient::mapProduct() y, si la
 * paginación es distinta, HttpBlessingClient::products().
 */
interface BlessingClient
{
    /** ¿Hay una conexión utilizable? */
    public function isConfigured(): bool;

    /** Origen para la bitácora: `api`, `demo` o `ninguno`. */
    public function source(): string;

    /**
     * Recorre TODO el catálogo desde `$cursor` (null: desde el inicio),
     * pidiendo las páginas que haga falta.
     *
     * @return iterable<BlessingProduct>
     *
     * @throws BlessingException si la API no responde o responde algo inválido.
     */
    public function products(?string $cursor = null): iterable;
}
