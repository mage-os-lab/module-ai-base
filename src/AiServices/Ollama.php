<?php

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;

class Ollama implements AiServiceConfigurationInterface
{
    public function getCode(): string
    {
        return 'ollama';
    }

    public function getName(): string
    {
        return 'Ollama';
    }

    public function getConfigurationTemplate(): string
    {
        return <<<TABLE
            <table>
                <tr>
                    <th>Base URL</th>
                    <td><input type="text" name="<%- _fieldName %>[ollama][base_url]" value="http://localhost:11434" /></td>
                </tr>
                <tr>
                    <th>Model</th>
                    <td>
                        <select name="<%- _fieldName %>[ollama][model]">
                            <option value="llama3">llama3</option>
                            <option value="phi3">phi3</option>
                        </select>
                    </td>
                </tr>
            </table>
        TABLE;
    }
}
