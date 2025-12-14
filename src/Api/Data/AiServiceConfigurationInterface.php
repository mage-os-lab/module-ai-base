<?php

namespace MageOS\AiBase\Api\Data;

interface AiServiceConfigurationInterface
{
    public function getCode(): string;

    public function getName(): string;

    public function getConfigurationTemplate(): string;
}
