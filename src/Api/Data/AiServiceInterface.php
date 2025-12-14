<?php

namespace MageOS\AiBase\Api\Data;

interface AiServiceInterface
{
    public function getCode(): string;

    public function getConfiguration(): array;
}
