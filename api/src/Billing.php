<?php
declare(strict_types=1);

final class Billing
{
    public const DAY = 86400000;

    public static function business(): array
    {
        $default = [
            'plans' => [
                ['id' => 'start', 'name' => 'شروع', 'days' => 30, 'priceIRR' => 8490000, 'description' => 'یک ماه برای شروع ارتباط‌های بهتر',
                 'features' => ['دایرکت هوشمند برای کامنت، دایرکت و استوری', 'قفل فالو و ارسال فایل آموزشی', 'آمار زنده و مدیریت مخاطب']],
                ['id' => 'growth', 'name' => 'رشد', 'days' => 90, 'priceIRR' => 25470000, 'description' => 'سه ماه برای ساختن عادت‌های بهتر',
                 'features' => ['همه‌ی امکانات شروع', '۹۰ روز دسترسی', 'گزارش تلگرامی']],
                ['id' => 'studio', 'name' => 'استودیو', 'days' => 365, 'priceIRR' => 101880000, 'description' => 'یک سال همراه با ایده‌های بزرگ‌تر',
                 'features' => ['همه‌ی امکانات رشد', '۳۶۵ روز دسترسی', 'اولویت در پشتیبانی']],
            ],
            'support' => 'fesghli',
            'cryptoAddress' => '',
            'cryptoNetwork' => 'TRON',
            'cryptoEnabled' => false,
            'salesEnabled' => true,
            'trxPriceIRR' => 0,
            'zarinpalEnabled' => false,
        ];
        $b = array_merge($default, Db::kv('business', []) ?? []);
        $b['zarinpalReady'] = (bool)Config::get('zarinpal.merchant_id');
        return $b;
    }

    public static function catalog(): array
    {
        $b = self::business();
        return array_intersect_key($b, array_flip(['plans', 'support', 'cryptoEnabled', 'salesEnabled', 'zarinpalEnabled', 'zarinpalReady', 'cryptoNetwork']));
    }

    public static function saveBusiness(): array
    {
        $admin = Auth::admin();
        $b = Http::body();
        $cur = self::business();
        $plans = [];
        foreach ($cur['plans'] as $p) {
            $in = current(array_filter($b['plans'] ?? [], fn($x) => ($x['id'] ?? '') === $p['id'])) ?: [];
            $name = trim((string)($in['name'] ?? $p['name']));
            $days = (int)($in['days'] ?? $p['days']);
            $price = (int)($in['priceIRR'] ?? $p['priceIRR']);
            if ($name === '' || mb_strlen($name) > 40) throw new ApiError('نام پلن باید بین ۱ تا ۴۰ کاراکتر باشد.');
            if ($days < 1 || $days > 3650) throw new ApiError('مدت پلن باید بین ۱ تا ۳۶۵۰ روز باشد.');
            if ($price < 10000 || $price > 100000000000) throw new ApiError('قیمت پلن معتبر نیست.');
            $plans[] = ['name' => $name, 'days' => $days, 'priceIRR' => $price] + $p;
        }
        $addr = trim((string)($b['cryptoAddress'] ?? $cur['cryptoAddress']));
        if ($addr !== '' && !Tron::validAddress($addr)) throw new ApiError('آدرس کیف پول TRON معتبر نیست.');
        $next = [
            'plans' => $plans,
            'support' => Util::str($b + ['support' => $cur['support']], 'support', 40),
            'cryptoAddress' => $addr,
            'cryptoNetwork' => 'TRON',
            'cryptoEnabled' => !empty($b['cryptoEnabled']) && $addr !== '',
            'salesEnabled' => !empty($b['salesEnabled']),
            'trxPriceIRR' => max(0, (int)($b['trxPriceIRR'] ?? $cur['trxPriceIRR'])),
            'zarinpalEnabled' => array_key_exists('zarinpalEnabled', $b) ? !empty($b['zarinpalEnabled']) : (bool)$cur['zarinpalEnabled'],
        ];
        if ($next['cryptoEnabled'] && $next['trxPriceIRR'] <= 0) $next['trxPriceIRR'] = self::trxRate();
        Db::setKv('business', $next);
        Util::audit($admin, 'تنظیمات فروش به‌روز شد');
        return self::business();
    }

    /** Current TRX price in IRR from Nobitex (public, no key). 0 if unavailable. */
    public static function trxRate(): int
    {
        try {
            [$s, $j] = Http::get('https://api.nobitex.ir/v2/orderbook/TRXIRT', [], 8);
            $toman = (float)($j['lastTradePrice'] ?? 0);
            return $s === 200 && $toman > 0 ? (int)round($toman * 10) : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private static function code(): string
    {
        do {
            $c = 'RP-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        } while (Db::value("SELECT 1 FROM orders WHERE code=?", [$c]));
        return $c;
    }

    public static function present(array $o): array
    {
        $o['snapshot'] = json_decode($o['snapshot'], true) ?: [];
        foreach (['amount', 'amount_irr', 'days', 'created', 'expires', 'paid_at'] as $k) if (isset($o[$k])) $o[$k] = (int)$o[$k];
        unset($o['request_id'], $o['authority']);
        return $o;
    }

    public static function createOrder(): array
    {
        $u = Auth::require();
        $b = Http::body();
        $biz = self::business();
        if (!$biz['salesEnabled'] && $u['role'] !== 'admin') throw new ApiError('فروش موقتاً متوقف است. کمی بعد دوباره سر بزن.', 409);
        $kind = ($b['kind'] ?? '') === 'topup' ? 'topup' : 'subscription';
        $method = (string)($b['method'] ?? '');
        $allowed = $kind === 'topup' ? ['zarinpal', 'rial'] : ['zarinpal', 'rial', 'crypto', 'wallet'];
        if (!in_array($method, $allowed, true)) throw new ApiError('روش پرداخت معتبر نیست.');
        if ($method === 'zarinpal' && !($biz['zarinpalEnabled'] && $biz['zarinpalReady'])) throw new ApiError('درگاه پرداخت آنلاین هنوز فعال نشده است.', 409);
        if ($method === 'crypto' && !$biz['cryptoEnabled']) throw new ApiError('پرداخت TRX هنوز فعال نشده است.', 409);
        $requestId = substr(preg_replace('/[^a-zA-Z0-9-]/', '', (string)($b['requestId'] ?? '')), 0, 64) ?: Util::uuid();
        Util::limit('order:' . $u['id'], 12, 3600, 'سفارش‌های زیادی ثبت کرده‌ای. کمی بعد دوباره امتحان کن.');

        if ($existing = Db::one("SELECT * FROM orders WHERE user_id=? AND request_id=?", [$u['id'], $requestId])) {
            return self::afterCreate($existing);
        }

        if ($kind === 'subscription') {
            $plan = current(array_filter($biz['plans'], fn($p) => $p['id'] === ($b['planId'] ?? ''))) ?: null;
            if (!$plan) throw new ApiError('پلن انتخاب‌شده پیدا نشد.');
            $irr = (int)$plan['priceIRR'];
            $days = (int)$plan['days'];
            $snapshot = ['planName' => $plan['name'], 'support' => $biz['support']];
        } else {
            $irr = (int)($b['amount'] ?? 0);
            if ($irr < 10000 || $irr > 1000000000) throw new ApiError('مبلغ شارژ باید بین ۱٬۰۰۰ تا ۱۰۰٬۰۰۰٬۰۰۰ تومان باشد.');
            $plan = null;
            $days = 0;
            $snapshot = ['planName' => 'شارژ کیف پول', 'support' => $biz['support']];
        }

        $amount = $irr;
        $currency = 'IRR';
        $expires = Util::now() + ($method === 'rial' ? 3 * self::DAY : 30 * 60000);
        if ($method === 'crypto') {
            $rate = (int)$biz['trxPriceIRR'] ?: self::trxRate();
            if ($rate <= 0) throw new ApiError('نرخ TRX در دسترس نیست. کمی بعد دوباره امتحان کن.', 503);
            $amount = self::uniqueTrxAmount((int)ceil($irr / $rate * 100) * 10000);
            $currency = 'TRX';
            $snapshot += ['cryptoAddress' => $biz['cryptoAddress'], 'rateIRR' => $rate];
        }

        $id = Util::uuid();
        $now = Util::now();
        $insert = fn(string $status) => Db::run("INSERT INTO orders (id, code, user_id, request_id, kind, plan_id, days, amount, amount_irr, currency, method, status, snapshot, created, expires)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$id, self::code(), $u['id'], $requestId, $kind, $plan['id'] ?? null, $days, $amount, $irr, $currency, $method, $status, json_encode($snapshot, JSON_UNESCAPED_UNICODE), $now, $expires]);

        if ($method === 'wallet') {
            Db::tx(function () use ($u, $irr, $insert, $id, $plan) {
                $bal = (int)Db::value("SELECT balance FROM users WHERE id=? FOR UPDATE", [$u['id']]);
                if ($bal < $irr) throw new ApiError('موجودی کیف پول کافی نیست. ابتدا کیف پولت را شارژ کن.', 402);
                Db::run("UPDATE users SET balance=balance-? WHERE id=?", [$irr, $u['id']]);
                Db::run("INSERT INTO wallet_ledger (user_id, amount, reason, order_id, created) VALUES (?,?,?,?,?)", [$u['id'], -$irr, 'خرید اشتراک ' . $plan['name'], $id, Util::now()]);
                $insert('pending');
            });
            self::fulfil($id, 'کیف پول');
        } else {
            $insert('pending');
            if ($method === 'rial') Telegram::admin("🧾 سفارش ریالی تازه\n{$u['name']} · " . Util::toman($irr) . " تومان\nبرای تأیید به پنل مدیریت برو.");
        }
        return self::afterCreate(Db::one("SELECT * FROM orders WHERE id=?", [$id]));
    }

    private static function afterCreate(array $o): array
    {
        $out = ['id' => $o['id'], 'code' => $o['code'], 'status' => $o['status']];
        if ($o['method'] === 'zarinpal' && $o['status'] === 'pending') $out['payUrl'] = Zarinpal::start($o);
        return $out;
    }

    /** Each pending crypto invoice gets a unique amount so the payment identifies the order. */
    private static function uniqueTrxAmount(int $baseSun): int
    {
        for ($i = 0; $i < 50; $i++) {
            $sun = $baseSun + random_int(1, 9999) * 1; // last 4 digits identify the order
            if (!Db::value("SELECT 1 FROM orders WHERE method='crypto' AND status='pending' AND amount=?", [$sun])) return $sun;
        }
        throw new ApiError('صدور فاکتور ممکن نشد. دوباره امتحان کن.', 503);
    }

    public static function proof(string $id): array
    {
        $u = Auth::require();
        $o = Db::one("SELECT * FROM orders WHERE id=? AND user_id=?", [$id, $u['id']]);
        if (!$o) throw new ApiError('سفارش پیدا نشد.', 404);
        if ($o['status'] !== 'pending') throw new ApiError('این سفارش دیگر در انتظار پرداخت نیست.', 409);
        $proof = Util::str(Http::body(), 'proof', 500, true, 'اطلاعات پرداخت');
        Db::run("UPDATE orders SET proof=? WHERE id=?", [$proof, $id]);
        Telegram::admin("💬 اطلاعات پرداخت برای {$o['code']} ثبت شد:\n$proof");
        return ['ok' => true];
    }

    /** User submits a TxID; we check it on-chain and activate instantly when valid. */
    public static function verifyTrx(string $id): array
    {
        $u = Auth::require();
        Util::limit('trx:' . $u['id'], 20, 3600);
        $o = Db::one("SELECT * FROM orders WHERE id=? AND user_id=?", [$id, $u['id']]);
        if (!$o || $o['method'] !== 'crypto') throw new ApiError('سفارش پیدا نشد.', 404);
        if ($o['status'] === 'approved') return ['ok' => true];
        if ($o['status'] !== 'pending') throw new ApiError('این سفارش دیگر قابل پرداخت نیست.', 409);
        $txid = strtolower(trim((string)(Http::body()['txid'] ?? '')));
        if (!preg_match('/^[0-9a-f]{64}$/', $txid)) throw new ApiError('شناسه‌ی تراکنش باید ۶۴ کاراکتر هگز باشد.');
        if (Db::value("SELECT 1 FROM orders WHERE txid=? AND id<>?", [$txid, $id])) throw new ApiError('این تراکنش قبلاً برای سفارش دیگری ثبت شده است.', 409);
        $snap = json_decode($o['snapshot'], true);
        $tx = Tron::transfer($txid);
        if (!$tx) throw new ApiError('تراکنش هنوز در شبکه تأیید نهایی نشده یا پیدا نشد. یک دقیقه بعد دوباره امتحان کن.', 409);
        if (!Tron::sameAddress($tx['to'], $snap['cryptoAddress'] ?? '')) throw new ApiError('مقصد این تراکنش آدرس کیف پول ریپل نیست.', 400);
        if ((int)$tx['amount'] !== (int)$o['amount']) throw new ApiError('مبلغ تراکنش با مبلغ دقیق فاکتور برابر نیست. برای بررسی دستی با پشتیبانی در تماس باش.', 400);
        if ($tx['time'] && $tx['time'] < (int)$o['created'] - 10 * 60000) throw new ApiError('این تراکنش قبل از صدور فاکتور انجام شده است.', 400);
        Db::run("UPDATE orders SET txid=? WHERE id=? AND txid IS NULL", [$txid, $id]);
        self::fulfil($id, 'TRX ' . substr($txid, 0, 10));
        return ['ok' => true];
    }

    /**
     * Mark an order paid and apply it exactly once (row lock + status check).
     * Subscription days stack on top of any remaining time.
     */
    public static function fulfil(string $orderId, string $via, ?array $actor = null, string $note = ''): bool
    {
        $done = Db::tx(function () use ($orderId, $note) {
            $o = Db::one("SELECT * FROM orders WHERE id=? FOR UPDATE", [$orderId]);
            if (!$o || $o['status'] !== 'pending') return null;
            $now = Util::now();
            Db::run("UPDATE orders SET status='approved', paid_at=?, note=? WHERE id=?", [$now, $note ?: $o['note'], $orderId]);
            if ($o['kind'] === 'subscription') {
                Db::run("UPDATE users SET sub_expires = GREATEST(sub_expires, ?) + ?, sub_plan=? WHERE id=?", [$now, (int)$o['days'] * self::DAY, $o['plan_id'], $o['user_id']]);
            } else {
                Db::run("UPDATE users SET balance=balance+? WHERE id=?", [(int)$o['amount_irr'], $o['user_id']]);
                Db::run("INSERT INTO wallet_ledger (user_id, amount, reason, order_id, created) VALUES (?,?,?,?,?)", [$o['user_id'], (int)$o['amount_irr'], 'شارژ کیف پول · ' . $o['code'], $orderId, $now]);
            }
            return $o;
        });
        if (!$done) return false;
        $user = Db::one("SELECT * FROM users WHERE id=?", [$done['user_id']]);
        $snap = json_decode($done['snapshot'], true);
        if ($done['kind'] === 'subscription') {
            $left = (int)ceil(((int)$user['sub_expires'] - Util::now()) / self::DAY);
            $title = 'اشتراکت فعال شد 🎉';
            $body = "اشتراک «{$snap['planName']}» فعال شد و {$left} روز اعتبار داری. دایرکت هوشمند از همین لحظه روی پیجت کار می‌کند.";
        } else {
            $title = 'کیف پولت شارژ شد';
            $body = 'مبلغ ' . Util::toman((int)$done['amount_irr']) . ' تومان به کیف پولت اضافه شد.';
        }
        Util::notify($user['id'], $title, $body, 'success');
        Util::audit($actor, "پرداخت سفارش {$done['code']} تأیید شد", ['via' => $via]);
        Telegram::admin("✅ پرداخت تأیید شد ({$via})\n{$done['code']} · {$user['name']} · " . Util::toman((int)$done['amount_irr']) . ' تومان');
        try {
            if (Mailer::ready()) Mailer::sendNotice($user['email'], $title, $body, 'مشاهده‌ی اشتراک', Config::get('app_url') . '/app/billing');
        } catch (Throwable $e) {
            error_log('mail after payment: ' . $e->getMessage());
        }
        return true;
    }

    public static function review(string $id): array
    {
        $admin = Auth::admin();
        $b = Http::body();
        $o = Db::one("SELECT * FROM orders WHERE id=?", [$id]);
        if (!$o) throw new ApiError('سفارش پیدا نشد.', 404);
        if ($o['status'] !== 'pending') throw new ApiError('این سفارش قبلاً بررسی شده است.', 409);
        $note = Util::str($b, 'note', 500);
        if (($b['status'] ?? '') === 'approved') {
            if (empty($b['confirmed'])) throw new ApiError('برای تأیید، بررسی مستقل دریافت وجه لازم است.');
            self::fulfil($id, 'تأیید مدیر', $admin, $note);
        } elseif (($b['status'] ?? '') === 'rejected') {
            Db::run("UPDATE orders SET status='rejected', note=? WHERE id=? AND status='pending'", [$note, $id]);
            if ($o['method'] === 'wallet') self::refund($o);
            Util::notify($o['user_id'], 'سفارش تأیید نشد', $note ?: "سفارش {$o['code']} تأیید نشد. برای پیگیری با پشتیبانی در تماس باش.", 'warning');
            Util::audit($admin, "سفارش {$o['code']} رد شد", ['note' => $note]);
        } else {
            throw new ApiError('وضعیت انتخاب‌شده معتبر نیست.');
        }
        return ['ok' => true];
    }

    private static function refund(array $o): void
    {
        Db::run("UPDATE users SET balance=balance+? WHERE id=?", [(int)$o['amount_irr'], $o['user_id']]);
        Db::run("INSERT INTO wallet_ledger (user_id, amount, reason, order_id, created) VALUES (?,?,?,?,?)", [$o['user_id'], (int)$o['amount_irr'], 'بازگشت وجه ' . $o['code'], $o['id'], Util::now()]);
    }

    public static function grant(string $userId): array
    {
        $admin = Auth::admin();
        $b = Http::body();
        $days = (int)($b['days'] ?? 0);
        if ($days < 1 || $days > 3650) throw new ApiError('تعداد روز باید بین ۱ تا ۳۶۵۰ باشد.');
        $reason = Util::str($b, 'reason', 200, true, 'دلیل');
        $u = Db::one("SELECT * FROM users WHERE id=?", [$userId]);
        if (!$u) throw new ApiError('کاربر پیدا نشد.', 404);
        Db::run("UPDATE users SET sub_expires = GREATEST(sub_expires, ?) + ?, sub_plan='gift' WHERE id=?", [Util::now(), $days * self::DAY, $userId]);
        Util::notify($userId, 'هدیه‌ای از ریپل 🎁', "{$days} روز به اشتراکت اضافه شد. ($reason)", 'success');
        Util::audit($admin, "{$days} روز به اشتراک {$u['name']} اضافه شد", ['reason' => $reason]);
        return ['ok' => true];
    }

    /** Housekeeping, called from cron. */
    public static function expireStale(): int
    {
        return Db::run("UPDATE orders SET status='expired' WHERE status='pending' AND method IN ('zarinpal','crypto') AND expires < ?", [Util::now() - 6 * 3600000])->rowCount();
    }
}

final class Zarinpal
{
    private static function base(): string
    {
        return Config::get('zarinpal.sandbox') ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';
    }

    public static function start(array $o): string
    {
        if ($o['authority']) return self::base() . '/pg/StartPay/' . $o['authority'];
        $user = Db::one("SELECT email FROM users WHERE id=?", [$o['user_id']]);
        [$s, $j] = Http::post(self::base() . '/pg/v4/payment/request.json', [
            'merchant_id' => Config::get('zarinpal.merchant_id'),
            'amount' => (int)$o['amount_irr'],
            'currency' => 'IRR',
            'callback_url' => Config::get('app_url') . '/api/pay/zarinpal/callback?order=' . $o['id'],
            'description' => 'ریپل · ' . (json_decode($o['snapshot'], true)['planName'] ?? 'سفارش') . ' · ' . $o['code'],
            'metadata' => ['email' => $user['email'] ?? '', 'order_id' => $o['code']],
        ]);
        $authority = $j['data']['authority'] ?? null;
        if ($s !== 200 || (int)($j['data']['code'] ?? 0) !== 100 || !$authority) {
            error_log('zarinpal request failed: ' . json_encode($j));
            throw new ApiError('اتصال به درگاه پرداخت برقرار نشد. کمی بعد دوباره امتحان کن.', 502);
        }
        Db::run("UPDATE orders SET authority=? WHERE id=?", [$authority, $o['id']]);
        return self::base() . '/pg/StartPay/' . $authority;
    }

    /** Browser returns here from the bank. Verify server-to-server, then activate. */
    public static function callback(): never
    {
        $to = fn(string $q) => Http::redirect(Config::get('app_url') . '/app/billing?' . $q);
        $o = Db::one("SELECT * FROM orders WHERE id=? AND method='zarinpal'", [(string)($_GET['order'] ?? '')]);
        $authority = (string)($_GET['Authority'] ?? '');
        if (!$o || !$authority || !hash_equals((string)$o['authority'], $authority)) $to('payment=invalid');
        if ($o['status'] === 'approved') $to('payment=ok&code=' . urlencode($o['code']));
        if (($_GET['Status'] ?? '') !== 'OK') {
            Db::run("UPDATE orders SET status='failed', note='پرداخت توسط کاربر لغو شد یا ناموفق بود.' WHERE id=? AND status='pending'", [$o['id']]);
            $to('payment=cancel&code=' . urlencode($o['code']));
        }
        [$s, $j] = Http::post(self::base() . '/pg/v4/payment/verify.json', [
            'merchant_id' => Config::get('zarinpal.merchant_id'),
            'amount' => (int)$o['amount_irr'],
            'authority' => $authority,
        ]);
        $code = (int)($j['data']['code'] ?? 0);
        if ($s === 200 && ($code === 100 || $code === 101)) {
            Db::run("UPDATE orders SET ref_id=? WHERE id=?", [(string)($j['data']['ref_id'] ?? ''), $o['id']]);
            Billing::fulfil($o['id'], 'زرین‌پال ' . ($j['data']['ref_id'] ?? ''));
            $to('payment=ok&code=' . urlencode($o['code']));
        }
        error_log('zarinpal verify failed: ' . json_encode($j));
        Db::run("UPDATE orders SET status='failed', note='تأیید پرداخت از سمت بانک انجام نشد. اگر مبلغ کسر شده، طی ۷۲ ساعت برمی‌گردد.' WHERE id=? AND status='pending'", [$o['id']]);
        $to('payment=failed&code=' . urlencode($o['code']));
    }
}
