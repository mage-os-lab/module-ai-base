<?php

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;

class LmStudio implements AiServiceConfigurationInterface
{
    public function getCode(): string
    {
        return 'lmstudio';
    }

    public function getName(): string
    {
        return 'LM Studio';
    }

    public function getConfigurationTemplate(): string
    {
        return <<<TABLE
            <table>
                <tr>
                    <th>Base URL</th>
                    <td><input type="text" name="<%- _fieldName %>[lmstudio][base_url]" value="http://localhost:1234/v1" /></td>
                </tr>
                <tr>
                    <th>Model</th>
                    <td><input type="text" name="<%- _fieldName %>[lmstudio][model]" value="local-model" /></td>
                </tr>
            </table>
        TABLE;
    }
}
