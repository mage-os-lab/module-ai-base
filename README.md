# Mage-OS AI Base module

The goal of this module is to provide a way to allow to configure multiple AI backends.

## Installation

```bash
composer require mage-os/module-ai-base
php bin/magento module:enable MageOS_AiBase
```

You can find the new configuration option in System > Configuration > Services -> AI Configuration.

## Usage

If you have configured AI backends, you can fetch the configuration using these methods: 

```php
AiServiceConfigurationInterface::getAll($code): array
AiServiceConfigurationInterface::getByCode($code): array
```

Both methods return an array of `\MageOS\AiBase\Api\Data\AiServiceInterface` objects.

```php
class MyAiFunctionality {
    public function __construct(AiServiceConfigurationInterface $aiServiceConfiguration) {
        $this->aiServiceConfiguration = $aiServiceConfiguration;
    }
    
    public function doSomething() {
    	$openAiCredentials = $this->aiServiceConfiguration->getByCode('openai');

        // $openAiCredentials = an array of \MageOS\AiBase\Api\Data\AiServiceInterface objects
    }
}
```
