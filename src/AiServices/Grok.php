<?php

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;

class Grok implements AiServiceConfigurationInterface
{
    public function getCode(): string
    {
        return 'grok';
    }

    public function getName(): string
    {
        return 'Grok';
    }

    public function getConfigurationTemplate(): string
    {
        return <<<TABLE
            <table>
                <tr>
                    <th>API Key</th>
                    <td><input type="password" name="<%- _fieldName %>[grok][apikey]" /></td>
                </tr>
                <tr>
                    <th>Model</th>
                    <td><input type="text" name="<%- _fieldName %>[grok][model]" value="grok-beta" /></td>
                </tr>
            </table>
        TABLE;
    }
}
