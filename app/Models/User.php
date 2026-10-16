<?php
namespace App\Models;
class User extends Model
{
    public const ADMIN = 'admin';
    public const REFEREE = 'referee';
    public const ROLES = [self::ADMIN, self::REFEREE];

    public $id;
    public $name;
    public $email;
    public $password_hash;
    public $role;
    public $active;
    public $must_change_password;
    public $last_login_at;
    public $password_changed_at;
    public $created_at;
    public $updated_at;
    public function initialize(): void {
        $this->setSource('users');
    }
    public function beforeValidationOnCreate(): void {
        $this->created_at = $this->updated_at = date('Y-m-d H:i:s');
        // Phalcon treats a zero column default as "no default", so flags are set here explicitly.
        $this->active ??= 1;
        $this->must_change_password ??= 0;
    }
    public function beforeValidationOnUpdate(): void { $this->updated_at = date('Y-m-d H:i:s'); }

    public function isActive(): bool { return (bool)(int)$this->active; }

    /** What the session and the interface know about the account (never the password hash). */
    public function publicData(): array {
        return [
            'id' => (int)$this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'active' => (int)$this->active,
            'must_change_password' => (int)$this->must_change_password,
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
        ];
    }
}
