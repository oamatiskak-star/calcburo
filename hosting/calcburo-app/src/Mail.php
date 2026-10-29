<?php
declare(strict_types=1);

namespace CalcBuro;

interface Mailer
{
    /**
     * @param array<string, string> $headers
     * @return bool true = door de lokale mailserver geaccepteerd (geen aflevergarantie)
     */
    public function verstuur(string $aan, string $onderwerp, string $tekst, array $headers): bool;
}

/** Verstuurt via PHP mail() naar de lokale MTA van de hosting (Plesk/Postfix). */
final class PhpMailer implements Mailer
{
    public function __construct(private string $envelopeAfzender)
    {
    }

    public function verstuur(string $aan, string $onderwerp, string $tekst, array $headers): bool
    {
        $params = $this->envelopeAfzender !== '' ? '-f' . $this->envelopeAfzender : '';
        return @mail($aan, $onderwerp, chunk_split(base64_encode($tekst), 76, "\r\n"), $headers, $params);
    }
}

/**
 * Stelt de berichten op. De bevestiging aan de aanvrager bevat bewust geen ingevulde tekst:
 * zo kan het formulier niet worden misbruikt om eigen inhoud naar derden te sturen.
 */
final class Berichten
{
    /**
     * @param array<string, mixed> $mailConfig
     * @return array<string, string>
     */
    public static function basisHeaders(array $mailConfig): array
    {
        return [
            'From' => $mailConfig['van_naam'] . ' <' . $mailConfig['van'] . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'base64',
            'Auto-Submitted' => 'auto-generated',
        ];
    }

    /**
     * @param array<string, string> $velden
     * @param list<array<string, mixed>> $documenten
     * @return array{onderwerp: string, tekst: string}
     */
    public static function intern(string $ref, string $modus, array $velden, array $documenten, string $locatie, int $nu): array
    {
        $regels = [
            'Nieuwe calculatieaanvraag via calcburo.nl' . ($modus === 'test' ? ' (TESTMODUS)' : ''),
            '',
            'Referentie: ' . $ref,
            'Ontvangen: ' . gmdate('Y-m-d H:i', $nu) . ' UTC',
            '',
            'Naam: ' . $velden['naam'],
            'Bedrijf: ' . self::ofLeeg($velden['bedrijf']),
            'E-mail: ' . $velden['email'],
            'Telefoon: ' . self::ofLeeg($velden['telefoon']),
            'Rol: ' . self::ofLeeg($velden['rol']),
            'Gewenste uitwerking: ' . self::ofLeeg($velden['uitwerking']),
            'Projectlocatie: ' . self::ofLeeg($velden['projectlocatie']),
            'Oplevering / inschrijfdatum: ' . self::ofLeeg($velden['planning']),
            '',
            'Projectomschrijving:',
            $velden['project'],
            '',
            'Documenten: ' . count($documenten),
        ];
        foreach ($documenten as $d) {
            $regels[] = sprintf('- %s (%s, %s) -> %s', $d['oorspronkelijke_naam'], $d['mime'], self::grootte((int) $d['grootte']), $d['opslagnaam']);
        }
        $regels[] = '';
        $regels[] = 'Locatie (alleen via Plesk Bestandsbeheer of SFTP, niet via de website):';
        $regels[] = $locatie;
        $regels[] = '';
        $regels[] = 'Beantwoorden gaat naar de aanvrager (Reply-To). Er zijn geen bijlagen of downloadlinks.';
        $regels[] = 'Geen opdracht: zonder vervolg wordt deze aanvraag na de bewaartermijn verwijderd.';
        $regels[] = 'Bij opdracht: map naar het opdrachtdossier verplaatsen of een leeg bestand BEWAREN in de map plaatsen.';

        return [
            'onderwerp' => ($modus === 'test' ? '[TEST] ' : '') . 'Nieuwe calculatieaanvraag ' . $ref,
            'tekst' => implode("\r\n", $regels) . "\r\n",
        ];
    }

    /** @return array{onderwerp: string, tekst: string} */
    public static function bevestiging(string $ref): array
    {
        $tekst = implode("\r\n", [
            'Geachte heer, mevrouw,',
            '',
            'Wij hebben uw calculatieaanvraag via calcburo.nl ontvangen en opgeslagen.',
            '',
            'Uw referentienummer: ' . $ref,
            '',
            'Hoe het verder gaat:',
            '1. Wij beoordelen uw vraag, de stukken en de planning.',
            '2. U ontvangt een voorstel met scope, planning en vergoeding.',
            '3. Pas na uw akkoord starten wij. Een aanvraag is nog geen opdracht.',
            '4. Betaling per factuur, volgens de afspraken in uw voorstel.',
            '',
            'Vermeld het referentienummer bij vragen of aanvullingen: calculatie@calcburo.nl.',
            'Heeft u deze aanvraag niet zelf gedaan? Laat het ons weten via info@calcburo.nl.',
            '',
            'Met vriendelijke groet,',
            'CalcBuro',
            '',
            'Dit is een automatisch bericht. Privacyverklaring: https://calcburo.nl/privacy.html',
        ]) . "\r\n";
        return ['onderwerp' => 'Ontvangstbevestiging calculatieaanvraag ' . $ref, 'tekst' => $tekst];
    }

    private static function ofLeeg(string $waarde): string
    {
        return $waarde === '' ? '(niet opgegeven)' : $waarde;
    }

    private static function grootte(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : max(1, (int) ceil($bytes / 1024)) . ' kB';
    }
}
