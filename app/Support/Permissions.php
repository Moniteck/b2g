<?php

namespace App\Support;

/**
 * Matriz única de permisos por rol de empresa.
 *
 * El rol vive en `company_user.role` y decide lo que el usuario puede hacer
 * DENTRO de esa empresa. La raíz del grupo (`users.is_root`) no es un rol: pasa
 * todos los permisos en todas las empresas y es la única que administra empresas.
 *
 * Uso:
 *   $user->hasPermission(Permissions::UPDATE)            // en la empresa activa
 *   $user->hasPermission(Permissions::DELETE, $companyId) // en una empresa concreta
 *   Gate::allows('use-ai')                               // mismo permiso, con guiones
 */
final class Permissions
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_OPERATOR = 'operator';

    public const ROLE_VIEWER = 'viewer';

    /** Roles válidos por empresa, de mayor a menor privilegio. */
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_VIEWER];

    public const ROLE_LABELS = [
        self::ROLE_ADMIN => 'Administrador',
        self::ROLE_OPERATOR => 'Operador',
        self::ROLE_VIEWER => 'Solo lectura',
    ];

    public const VIEW = 'view';

    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const DELETE = 'delete';

    public const ADJUDICATE = 'adjudicate';

    public const REVERT_OUTCOME = 'revert_outcome';

    public const SIGN_DOCUMENTS = 'sign_documents';

    public const USE_AI = 'use_ai';

    public const GENERATE_DOCUMENTS = 'generate_documents';

    public const VIEW_REPORTS = 'view_reports';

    public const MANAGE_KPIS = 'manage_kpis';

    public const MANAGE_SETTINGS = 'manage_settings';

    public const MANAGE_USERS = 'manage_users';

    public const VIEW_AUDIT = 'view_audit';

    public const VIEW_DIAGNOSTICS = 'view_diagnostics';

    /** Solo la raíz del grupo: ningún rol de empresa lo concede. */
    public const MANAGE_COMPANIES = 'manage_companies';

    private const ALL = [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_VIEWER];

    private const WRITERS = [self::ROLE_ADMIN, self::ROLE_OPERATOR];

    private const ADMINS = [self::ROLE_ADMIN];

    /**
     * Permiso => roles de empresa que lo tienen.
     *
     * @var array<string, array<int, string>>
     */
    public const MATRIX = [
        self::VIEW => self::ALL,
        self::VIEW_REPORTS => self::ALL,
        self::CREATE => self::WRITERS,
        self::UPDATE => self::WRITERS,
        self::ADJUDICATE => self::WRITERS,
        self::SIGN_DOCUMENTS => self::WRITERS,
        self::USE_AI => self::WRITERS,
        self::GENERATE_DOCUMENTS => self::WRITERS,
        self::DELETE => self::ADMINS,
        self::REVERT_OUTCOME => self::ADMINS,
        self::MANAGE_KPIS => self::ADMINS,
        self::MANAGE_SETTINGS => self::ADMINS,
        self::MANAGE_USERS => self::ADMINS,
        self::VIEW_AUDIT => self::ADMINS,
        self::VIEW_DIAGNOSTICS => self::ADMINS,
        self::MANAGE_COMPANIES => [],
    ];

    /** Permisos generales que se comparten con el frontend en `auth.can`. */
    public const SHARED = [
        self::CREATE, self::UPDATE, self::DELETE, self::ADJUDICATE, self::REVERT_OUTCOME,
        self::SIGN_DOCUMENTS, self::USE_AI, self::GENERATE_DOCUMENTS, self::VIEW_REPORTS,
        self::MANAGE_KPIS, self::MANAGE_SETTINGS, self::MANAGE_USERS, self::MANAGE_COMPANIES,
        self::VIEW_AUDIT, self::VIEW_DIAGNOSTICS,
    ];

    public static function roleAllows(?string $role, string $permission): bool
    {
        return $role !== null && in_array($role, self::MATRIX[$permission] ?? [], true);
    }

    /**
     * @return array<int, string>
     */
    public static function forRole(?string $role): array
    {
        return array_keys(array_filter(self::MATRIX, fn (array $roles) => in_array($role, $roles, true)));
    }

    /** Nombre del gate equivalente: `use_ai` => `use-ai`. */
    public static function gate(string $permission): string
    {
        return str_replace('_', '-', $permission);
    }
}
