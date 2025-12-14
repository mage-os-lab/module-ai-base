<?php

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;

class Google implements AiServiceConfigurationInterface
{
    public function getCode(): string
    {
        return 'google';
    }

    public function getName(): string
    {
        return 'Google';
    }

    public function getConfigurationTemplate(): string
    {
        return <<<TABLE
            <table>
                <tr>
                    <th>API Key</th>
                    <td><input type="password" name="<%- _fieldName %>[google][apikey]" /></td>
                </tr>
                <tr>
                    <th>Model</th>
                    <td>
                        <select name="<%- _fieldName %>[google][model]">
                            <option value="gemini-pro">gemini-pro</option>
                            <option value="gemini-1.5-pro">gemini-1.5-pro</option>
                        </select>
                    </td>
                </tr>
            </table>
        TABLE;
    }
}
