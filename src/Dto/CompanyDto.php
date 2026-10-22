<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Типизированный ответ о компании (аналог C# record).
 * Внешний «бесформенный» JSON парсится один раз на границе системы,
 * дальше по коду ходит только этот DTO.
 */
final readonly class CompanyDto
{
    public function __construct(
        public string $source,
        public string $inn,
        public string $name,
        public ?string $ogrn = null,
        public ?string $address = null,
        public ?string $status = null,
        public ?string $director = null,
        public array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            source:   (string) ($data['source'] ?? 'checko'),
            inn:      (string) ($data['inn'] ?? ''),
            name:     (string) ($data['name'] ?? ''),
            ogrn:     isset($data['ogrn']) ? (string) $data['ogrn'] : null,
            address:  isset($data['address']) ? (string) $data['address'] : null,
            status:   isset($data['status']) ? (string) $data['status'] : null,
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
