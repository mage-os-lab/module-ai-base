<?php

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;

class HuggingFace implements AiServiceConfigurationInterface
{
    public function getCode(): string
    {
        return 'huggingface';
    }

    public function getName(): string
    {
        return 'Hugging Face';
    }

    public function getConfigurationTemplate(): string
    {
        return <<<TABLE
            <table>
                <tr>
                    <th>API Key</th>
                    <td><input type="password" name="<%- _fieldName %>[huggingface][apikey]" /></td>
                </tr>
                <tr>
                    <th>Model</th>
                    <td><input type="text" name="<%- _fieldName %>[huggingface][model]" value="meta-llama/Llama-3-8b" /></td>
                </tr>
            </table>
        TABLE;
    }
}
