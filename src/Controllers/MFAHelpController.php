<?php

declare(strict_types=1);

namespace Restruct\MFABundle\Controllers;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\View\ArrayData;
use SilverStripe\View\Requirements;

/**
 * Simple controller to serve MFA help pages.
 * Access via /mfa-help/totp, /mfa-help/webauthn, etc.
 */
class MFAHelpController extends Controller
{
    private static string $url_segment = 'mfa-help';

    private static array $allowed_actions = [
        'index',
        'totp',
        'webauthn',
        'backupcodes',
    ];

    private static array $url_handlers = [
        '' => 'index',
        'totp' => 'totp',
        'webauthn' => 'webauthn',
        'backup-codes' => 'backupcodes',
    ];

    /**
     * Help content in Dutch. Override via config or create your own controller.
     */
    private static array $help_content = [
        'index' => [
            'title' => 'Tweestapsverificatie (MFA)',
            'content' => <<<'HTML'
<p>Tweestapsverificatie (MFA) voegt een extra beveiligingslaag toe aan je account.
Naast je wachtwoord heb je een tweede factor nodig om in te loggen.</p>

<h3>Beschikbare methodes</h3>
<ul>
    <li><strong>Authenticator app</strong> - Gebruik een app zoals Google Authenticator of 1Password</li>
    <li><strong>Beveiligingssleutel</strong> - Gebruik Touch ID, Face ID, of een USB-sleutel</li>
</ul>

<h3>Herstelcodes</h3>
<p>Bij het instellen van MFA ontvang je herstelcodes. Bewaar deze veilig -
je kunt ze gebruiken als je geen toegang hebt tot je normale verificatiemethode.</p>
HTML,
        ],
        'totp' => [
            'title' => 'Authenticator App Instellen',
            'content' => <<<'HTML'
<h3>Wat heb je nodig?</h3>
<p>Een authenticator app op je telefoon, bijvoorbeeld:</p>
<ul>
    <li>Google Authenticator (gratis)</li>
    <li>Microsoft Authenticator (gratis)</li>
    <li>1Password, Bitwarden, of Authy</li>
</ul>

<h3>Instellen</h3>
<ol>
    <li>Open de authenticator app op je telefoon</li>
    <li>Kies "Account toevoegen" of het + icoon</li>
    <li>Scan de QR-code die op het scherm verschijnt</li>
    <li>Voer de 6-cijferige code in die de app toont</li>
</ol>

<h3>Inloggen</h3>
<p>Bij het inloggen open je de app en voer je de actuele 6-cijferige code in.
De code verandert elke 30 seconden.</p>

<h3>Problemen?</h3>
<ul>
    <li><strong>Code werkt niet?</strong> Controleer of de tijd op je telefoon correct is ingesteld</li>
    <li><strong>Telefoon kwijt?</strong> Gebruik je herstelcodes of neem contact op met de beheerder</li>
</ul>
HTML,
        ],
        'webauthn' => [
            'title' => 'Beveiligingssleutel Instellen',
            'content' => <<<'HTML'
<h3>Wat is een beveiligingssleutel?</h3>
<p>Een beveiligingssleutel is een veilige manier om in te loggen met:</p>
<ul>
    <li><strong>Touch ID / Face ID</strong> - Ingebouwd in je Mac, iPhone of iPad</li>
    <li><strong>Windows Hello</strong> - Vingerafdruk of gezichtsherkenning op Windows</li>
    <li><strong>USB-sleutel</strong> - Een fysieke sleutel zoals YubiKey</li>
</ul>

<h3>Instellen</h3>
<ol>
    <li>Klik op "Beveiligingssleutel toevoegen"</li>
    <li>Je browser vraagt welk type sleutel je wilt gebruiken</li>
    <li>Kies "Dit apparaat" voor Touch ID/Face ID, of sluit je USB-sleutel aan</li>
    <li>Bevestig met je vingerafdruk, gezicht, of door de sleutel aan te raken</li>
</ol>

<h3>Passkeys (gesynchroniseerde sleutels)</h3>
<p>Moderne browsers kunnen je sleutel synchroniseren via iCloud of Google:</p>
<ul>
    <li><strong>iCloud Sleutelhanger</strong> - Werkt op al je Apple apparaten</li>
    <li><strong>Google Wachtwoordmanager</strong> - Werkt in Chrome op alle apparaten</li>
</ul>
<p>Zo kun je dezelfde sleutel op meerdere apparaten gebruiken.</p>

<h3>Meerdere apparaten</h3>
<p>Je kunt meerdere beveiligingssleutels registreren. Handig als je vanaf
verschillende apparaten inlogt of een backup wilt.</p>
HTML,
        ],
        'backupcodes' => [
            'title' => 'Herstelcodes',
            'content' => <<<'HTML'
<h3>Wat zijn herstelcodes?</h3>
<p>Herstelcodes zijn eenmalige codes die je kunt gebruiken als je geen toegang hebt
tot je normale verificatiemethode (telefoon kwijt, kapot, etc.).</p>

<h3>Belangrijk</h3>
<ul>
    <li>Elke code werkt maar één keer</li>
    <li>Bewaar ze op een veilige plek (niet op je telefoon!)</li>
    <li>Print ze uit of sla ze op in een wachtwoordmanager</li>
</ul>

<h3>Codes kwijt?</h3>
<p>Als je al je herstelcodes hebt gebruikt of kwijt bent, kun je nieuwe aanmaken
in je profielinstellingen. De oude codes worden dan ongeldig.</p>

<h3>Helemaal buitengesloten?</h3>
<p>Neem contact op met de beheerder. Zij kunnen je MFA-instellingen resetten
zodat je opnieuw kunt instellen.</p>
HTML,
        ],
    ];

    protected function init(): void
    {
        parent::init();

        // Prevent indexing of help pages
        $this->getResponse()->addHeader('X-Robots-Tag', 'noindex, nofollow');

        // Basic styling
        Requirements::customCSS(<<<'CSS'
.mfa-help {
    max-width: 800px;
    margin: 2rem auto;
    padding: 2rem;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    line-height: 1.6;
}
.mfa-help h1 { margin-bottom: 1.5rem; color: #333; }
.mfa-help h3 { margin-top: 1.5rem; color: #444; }
.mfa-help ul, .mfa-help ol { margin: 1rem 0; padding-left: 1.5rem; }
.mfa-help li { margin: 0.5rem 0; }
.mfa-help p { margin: 1rem 0; }
.mfa-help a { color: #0066cc; }
.mfa-help-nav { margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid #ddd; }
.mfa-help-nav a { margin-right: 1rem; }
CSS
        );
    }

    public function index(HTTPRequest $request): HTTPResponse|array
    {
        return $this->renderHelp('index');
    }

    public function totp(HTTPRequest $request): HTTPResponse|array
    {
        return $this->renderHelp('totp');
    }

    public function webauthn(HTTPRequest $request): HTTPResponse|array
    {
        return $this->renderHelp('webauthn');
    }

    public function backupcodes(HTTPRequest $request): HTTPResponse|array
    {
        return $this->renderHelp('backupcodes');
    }

    protected function renderHelp(string $page): array
    {
        $content = $this->config()->get('help_content')[$page] ?? null;

        if (!$content) {
            return $this->httpError(404, 'Help page not found');
        }

        return [
            'Title' => $content['title'],
            'Content' => $content['content'],
            'Navigation' => $this->getNavigation($page),
        ];
    }

    protected function getNavigation(string $currentPage): string
    {
        $pages = [
            'index' => 'Overzicht',
            'totp' => 'Authenticator App',
            'webauthn' => 'Beveiligingssleutel',
            'backupcodes' => 'Herstelcodes',
        ];

        $links = [];
        foreach ($pages as $key => $label) {
            $url = $this->Link($key === 'index' ? '' : $key);
            if ($key === $currentPage) {
                $links[] = "<strong>{$label}</strong>";
            } else {
                $links[] = "<a href=\"{$url}\">{$label}</a>";
            }
        }

        return implode(' | ', $links);
    }

    public function Link($action = null): string
    {
        return Controller::join_links(
            $this->config()->get('url_segment'),
            $action
        );
    }
}