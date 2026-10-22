<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * DTO компании — типизированная замена «сырого» array.
 *
 *readonly-класс (PHP 8.2) — аналог record в C#: неизменяемый,
 * с автосгенерированными свойствами из конструктора.
 */
final readonly class CompanyDto
{
    public function __construct(
        public string $source,
        public string $inn,
        public string $name,
        public ?string $ogrn,
        public string $address,
        public string $status,
        public ?string $director,
        /** @var array<string, mixed> Сырой ответ внешнего API (для отладки/будущих полей) */
        public array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            source:   (string) ($data['source'] ?? 'unknown'),
            inn:      (string) ($data['inn'] ?? ''),
            name:     (string) ($data['name'] ?? ''),
            ogrn:     isset($data['ogrn']) ? (string) $data['ogrn'] : null,
            address:  (string) ($data['address'] ?? ''),
            status:   (string) ($data['status'] ?? 'unknown'),
            director: isset($data['director']) ? (string) $data['director'] : null,
            raw:      (array) ($data['raw'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source'   => $this->source,
            'inn'      => $this->inn,
            'name'     => $this->name,
            'ogrn'     => $this->ogrn,
            'address'  => $this->address,
            'status'   => $this->status,
            'director' => $this->director,
        ];
    }
}
