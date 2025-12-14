<?php

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;

class Azure implements AiServiceConfigurationInterface
{
    public function getCode(): string
    {
        return 'azure';
    }

    public function getName(): string
    {
        return 'Azure';
    }

    public function getConfigurationTemplate(): string
    {
        return <<<TABLE
            <table>
                <tr>
                    <th>API Key</th>
                    <td><input type="password" name="<%- _fieldName %>[azure][apikey]" /></td>
                </tr>
                <tr>
                    <th>Deployment</th>
                    <td><input type="text" name="<%- _fieldName %>[azure][deployment]" value="gpt-35-turbo" /></td>
                </tr>
            </table>
        TABLE;
    }
}
