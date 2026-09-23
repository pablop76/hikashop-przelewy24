<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace Pablop76\Plugin\HikashopPayment\Przelewy24\Payment;

use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception\ApiException;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception\BlikException;

\defined('_JEXEC') or die;

/**
 * Obciążenie kodem BLIK wpisanym w sklepie.
 *
 * Przepływ różni się od przekierowania na stronę płatności: klient
 * zostaje w sklepie, a potwierdzenie robi w aplikacji swojego banku.
 * Kod jest jednorazowy i ważny około dwóch minut, więc trzeba go użyć
 * od razu po wpisaniu.
 */
final class BlikService
{
    /** Kod BLIK to dokładnie sześć cyfr. */
    private const CODE_PATTERN = '/^\d{6}$/';

    public function __construct(
        private readonly ApiClient $client,
        private readonly Logger $logger
    ) {
    }

    /**
     * Czy ciąg wygląda na kod BLIK.
     */
    public static function isValidCode(string $code): bool
    {
        return preg_match(self::CODE_PATTERN, $code) === 1;
    }

    /**
     * Usuwa z wpisanego kodu spacje i myślniki.
     *
     * Klienci przepisują kod z aplikacji na różne sposoby, a odrzucanie
     * wpisu za spację w środku byłoby złośliwością.
     */
    public static function normaliseCode(string $code): string
    {
        return preg_replace('/[\s-]+/', '', trim($code)) ?? '';
    }

    /**
     * Obciąża kodem BLIK transakcję zarejestrowaną wcześniej.
     *
     * @param  string  $token  token z transaction/register
     * @param  string  $code   sześciocyfrowy kod z aplikacji banku
     *
     * @return int  identyfikator transakcji nadany przez P24
     *
     * @throws BlikException  gdy P24 odrzuciło kod
     * @throws ApiException   gdy nie udało się porozumieć z P24
     */
    public function chargeByCode(string $token, string $code): int
    {
        $code = self::normaliseCode($code);

        if (!self::isValidCode($code)) {
            throw new BlikException('Kod BLIK musi mieć sześć cyfr', BlikError::WrongCode);
        }

        $this->logger->info('Obciążam kodem BLIK', ['token_len' => \strlen($token)]);

        try {
            $response = $this->client->post(Endpoints::BLIK_CHARGE_BY_CODE, [
                'token'    => $token,
                'blikCode' => $code,
            ]);
        } catch (ApiException $exception) {
            $powod = BlikError::fromCode($exception->getApiCode());

            $this->logger->error('P24 odrzuciło kod BLIK', [
                'http'  => $exception->getHttpStatus(),
                'powod' => $powod->value,
            ]);

            throw new BlikException($exception->getMessage(), $powod, $exception);
        }

        $p24OrderId = (int) $response->get('orderId', 0);

        if ($p24OrderId <= 0) {
            $this->logger->error('P24 przyjęło kod BLIK, ale nie zwróciło identyfikatora transakcji');

            throw new BlikException(
                'P24 nie zwróciło identyfikatora transakcji po obciążeniu BLIK',
                BlikError::GeneralError
            );
        }

        $this->logger->info('Kod BLIK przyjęty, czekamy na potwierdzenie w aplikacji banku', [
            'p24_order' => $p24OrderId,
        ]);

        return $p24OrderId;
    }
}
