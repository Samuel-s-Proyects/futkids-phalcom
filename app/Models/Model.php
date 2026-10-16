<?php
declare(strict_types=1);
namespace App\Models;

use App\Exceptions\HttpException;

/** Common helpers for every table model. */
abstract class Model extends \Phalcon\Mvc\Model
{
    /** @return static */
    public static function findOrFail(int $id, string $message = 'El registro no existe.'): static {
        $record = static::findFirst($id);
        if (!$record instanceof static) throw new HttpException($message, 404);
        return $record;
    }

    /** Saves or fails with the ORM messages, so callers never ignore a false return. */
    public function saveOrFail(string $message): void {
        if (!$this->save()) {
            throw new HttpException($message . ' ' . implode('; ', array_map('strval', $this->getMessages())), 409);
        }
    }

    public function deleteOrFail(string $message): void {
        if (!$this->delete()) throw new HttpException($message, 409);
    }
}
