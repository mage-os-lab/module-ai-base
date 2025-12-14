<?php

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;

class Anthropic implements AiServiceConfigurationInterface
{
    public function getCode(): string
    {
        return 'anthropic';
    }

    public function getName(): string
    {
        return 'Anthropic';
    }

    public function getConfigurationTemplate(): string
    {
        return <<<TABLE
            <table>
                <tr>
                    <th>API Key</th>
                    <td><input type="password" name="<%- _fieldName %>[anthropic][apikey]" /></td>
                </tr>
                <tr>
                    <th>Model</th>
                    <td>
                        <select name="<%- _fieldName %>[anthropic][model]">
                            <option value="claude-3-opus">claude-3-opus</option>
                            <option value="claude-3-sonnet">claude-3-sonnet</option>
                        </select>
                    </td>
                </tr>
            </table>
        TABLE;
    }
}
