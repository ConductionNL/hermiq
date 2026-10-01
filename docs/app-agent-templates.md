# Offer an agent template from your app

Your app can offer Hermiq an agent template for itself. A finance app can offer a help agent that knows its screens, for example. An organisation admin reviews the template before anyone uses it.

## How it works

1. Hermiq asks every installed app for templates. It does this on install, on every upgrade, and when an admin chooses "Check apps for templates" on the Store page.
2. Your app answers with one or more template packages.
3. Each package lands in the Store as quarantined, with the reason "Offered by the app `your-app-id`. Review before use." (with your own app id) Hermiq scans its system prompt, as it does for any imported template.
4. After approval, "Use this template" creates an agent tied to your app. Its `applicationSlug` is your app id, so it answers in your app.

An unchanged package is skipped on the next check. A changed package replaces your earlier template and sends it back to review.

## Listen for the event

Register a listener for `OCA\Hermiq\Event\CollectAgentTemplatesEvent` in your app's `Application::register()`. Use the class name as a string, so your app still installs without Hermiq:

```php
$context->registerEventListener(
    'OCA\Hermiq\Event\CollectAgentTemplatesEvent',
    OfferHelpAgentListener::class
);
```

In the listener, call `offer()` with your own app id and a package:

```php
public function handle(Event $event): void {
    if (method_exists($event, 'offer') === false) {
        return;
    }

    $event->offer('shillinq', json_encode([
        'name' => 'Finance helper',
        'description' => 'Answers questions about invoices and budgets.',
        'category' => 'finance',
        'systemPrompt' => 'You help colleagues find their way in the finance app.',
        'tools' => [],
        'version' => '1.0.0',
    ]));
}
```

The package uses the same format as the Store's "Export". `name` is required: Hermiq refuses a package without one.

## What Hermiq refuses

- An app id that is not installed on the instance.
- A package that is not a JSON object.
- A package without a name.

A refused offer is logged and counted. It never stops the other offers.

## Two templates with the same name

Hermiq knows an offer by your app id and the template name. Rename a template and Hermiq treats it as a new one; the old one stays until an admin removes it.
