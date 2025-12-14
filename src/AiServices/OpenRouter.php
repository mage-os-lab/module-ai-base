<?php

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;

class OpenRouter implements AiServiceConfigurationInterface
{
    public function getCode(): string
    {
        return 'openrouter';
    }

    public function getName(): string
    {
        return 'OpenRouter';
    }

    public function getConfigurationTemplate(): string
    {
        return <<<TABLE
            <table>
                <tr>
                    <th>API Key</th>
                    <td><input type="password" name="<%- _fieldName %>[openrouter][apikey]" /></td>
                </tr>
                <tr>
                    <th>Model</th>
                    <td>
                        <select name="<%- _fieldName %>[openrouter][model]">
                            <option value="openrouter/auto">openrouter/auto</option>
                            <option value="anthropic/claude-3-opus">anthropic/claude-3-opus</option>
                        </select>
                    </td>
                </tr>
            </table>
        TABLE;
    }
}
