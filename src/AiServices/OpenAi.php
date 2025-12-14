<?php

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;

class OpenAi implements AiServiceConfigurationInterface
{
    public function getCode(): string
    {
        return 'openai';
    }

    public function getName(): string
    {
        return 'OpenAI';
    }

    public function getConfigurationTemplate(): string
    {
        return <<<TABLE
            <table>
                <tr>
                    <th>API Key</th>
                    <td><input type="password" name="<%- _fieldName %>[openai][apikey]" /></td>
                </tr>
                <tr>
                    <th>Model</th>
                    <td>
                        <select name="<%- _fieldName %>[openai][model]">
                            <option value="gpt-3.5-turbo">gpt-3.5-turbo</option>
                        </select>
                    </td>
                </tr>
            </table>
        TABLE;
    }
}
