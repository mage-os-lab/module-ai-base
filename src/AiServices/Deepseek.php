<?php

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;

class Deepseek implements AiServiceConfigurationInterface
{
    public function getCode(): string
    {
        return 'deepseek';
    }

    public function getName(): string
    {
        return 'DeepSeek';
    }

    public function getConfigurationTemplate(): string
    {
        return <<<TABLE
            <table>
                <tr>
                    <th>API Key</th>
                    <td><input type="password" name="<%- _fieldName %>[deepseek][apikey]" /></td>
                </tr>
                <tr>
                    <th>Model</th>
                    <td><input type="text" name="<%- _fieldName %>[deepseek][model]" value="deepseek-chat" /></td>
                </tr>
            </table>
        TABLE;
    }
}
