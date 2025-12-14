<?php

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;

class Xai implements AiServiceConfigurationInterface
{
    public function getCode(): string
    {
        return 'xai';
    }

    public function getName(): string
    {
        return 'xAI';
    }

    public function getConfigurationTemplate(): string
    {
        return <<<TABLE
            <table>
                <tr>
                    <th>API Key</th>
                    <td><input type="password" name="<%- _fieldName %>[xai][apikey]" /></td>
                </tr>
                <tr>
                    <th>Model</th>
                    <td><input type="text" name="<%- _fieldName %>[xai][model]" value="grok-beta" /></td>
                </tr>
            </table>
        TABLE;
    }
}
