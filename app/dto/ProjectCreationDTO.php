<?php
declare(strict_types=1);

/** Strict, canonical DTO for the existing NEO form. Server-owned fields are never accepted. */
final class ProjectCreationDTO
{
    private const FIELDS = ['industry','purpose','colors','style','features','content','timeline','fullName','phone','email','messenger','messengerId','userNote'];
    private const SERVER_FIELDS = ['id','projectid','publicid','userid','owneruserid','adminid','role','status','progress','trackingcode','projecttype','createdat','submittedat','updatedat','requestid','adminnote','activitylog','changesnotified','idempotencykey','owner','ownerid','createdby','actorid'];

    /** @var array<string,mixed> */
    public readonly array $values;
    public readonly string $title;
    public readonly string $projectType;

    private function __construct(array $values, string $title, string $projectType)
    {
        $this->values = $values;
        $this->title = $title;
        $this->projectType = $projectType;
    }

    public static function fromArray(array $input): self
    {
        foreach ($input as $key => $_) {
            if (!is_string($key)) self::fail('INVALID_PROJECT_FIELD', 'ساختار یکی از فیلدهای درخواست معتبر نیست.');
            $normalized = strtolower((string) preg_replace('/[-_]/', '', $key));
            if (in_array($normalized, self::SERVER_FIELDS, true)) {
                self::fail('SERVER_OWNED_PROJECT_FIELD', 'فیلدهای مدیریتی و شناسه‌های داخلی باید توسط سرور تعیین شوند.');
            }
            if (!in_array($key, self::FIELDS, true)) {
                self::fail('UNKNOWN_PROJECT_FIELD', 'درخواست شامل فیلدی پشتیبانی‌نشده است.');
            }
        }
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $input)) self::fail('PROJECT_FIELD_REQUIRED', 'همه فیلدهای اصلی فرم باید ارسال شوند.');
        }

        $industry = self::enum($input['industry'], NeoCatalog::values('industries'), 'industry');
        $purpose = self::enum($input['purpose'], NeoCatalog::purposeValuesForIndustry($industry), 'purpose');
        $style = self::enum($input['style'], NeoCatalog::values('styles'), 'style');
        $timeline = self::enum($input['timeline'], NeoCatalog::values('timelines'), 'timeline');
        $features = self::stringList($input['features'], NeoCatalog::values('features'), 20, 'features');
        $colors = self::objectArray($input['colors'], 'colors');
        self::exactKeys($colors, ['palettes','customColors'], 'colors');
        $palettes = self::stringList($colors['palettes'], NeoCatalog::values('palettes'), 20, 'colors.palettes');
        $customColors = self::customColorList($colors['customColors']);
        if ($palettes === [] && $customColors === []) self::fail('PROJECT_COLORS_REQUIRED', 'حداقل یک پالت یا رنگ دلخواه انتخاب کنید.');

        $content = self::objectArray($input['content'], 'content');
        $contentCatalog = NeoCatalog::contentValuesByField();
        self::exactKeys($content, array_keys($contentCatalog), 'content');
        $normalizedContent = [];
        foreach ($contentCatalog as $field => $allowed) {
            $normalizedContent[$field] = self::enum($content[$field] ?? null, $allowed, 'content.' . $field);
        }

        $fullName = self::plainText($input['fullName'], 160, 'fullName', true);
        $phone = self::normalizePhone($input['phone']);
        $email = self::plainText($input['email'], 254, 'email', false);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            self::fail('INVALID_PROJECT_EMAIL', 'نشانی ایمیل واردشده معتبر نیست.');
        }
        $email = strtolower($email);
        $messenger = self::enum($input['messenger'], NeoCatalog::values('messengers'), 'messenger');
        $messengerId = self::plainText($input['messengerId'], 120, 'messengerId', false);
        $userNote = self::plainText($input['userNote'], 2000, 'userNote', false);

        $values = [
            'industry' => $industry,
            'purpose' => $purpose,
            'colors' => ['palettes' => $palettes, 'customColors' => $customColors],
            'style' => $style,
            'features' => $features,
            'content' => $normalizedContent,
            'timeline' => $timeline,
            'fullName' => $fullName,
            'phone' => $phone,
            'email' => $email,
            'messenger' => $messenger,
            'messengerId' => $messengerId,
            'userNote' => $userNote,
        ];
        $projectType = $industry . '_' . $purpose;
        if (strlen($projectType) > 64) self::fail('INVALID_PROJECT_TYPE', 'ترکیب صنعت و هدف بیش از حد مجاز است.');
        $title = NeoCatalog::label('industries', $industry) . ' — ' . NeoCatalog::purposeLabel($industry, $purpose);
        return new self($values, $title, $projectType);
    }

    public function requestHash(): string
    {
        try {
            return hash('sha256', json_encode($this->values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (JsonException $exception) {
            throw new RuntimeException('Project request could not be normalized safely.');
        }
    }

    /** All accepted NEO meaning is preserved in the canonical project brief. */
    public function brief(): array
    {
        return $this->values;
    }

    private static function objectArray(mixed $value, string $field): array
    {
        if (is_object($value)) $value = get_object_vars($value);
        if (!is_array($value) || array_is_list($value)) self::fail('INVALID_PROJECT_FIELD', 'ساختار یکی از بخش‌های فرم معتبر نیست: ' . $field);
        return $value;
    }

    private static function exactKeys(array $value, array $expected, string $field): void
    {
        foreach ($value as $key => $_) {
            if (!is_string($key) || !in_array($key, $expected, true)) self::fail('UNKNOWN_PROJECT_FIELD', 'ساختار یکی از بخش‌های فرم شامل فیلد ناشناخته است: ' . $field);
        }
        foreach ($expected as $key) if (!array_key_exists($key, $value)) self::fail('PROJECT_FIELD_REQUIRED', 'یک فیلد الزامی در فرم وجود ندارد: ' . $field);
    }

    private static function enum(mixed $value, array $allowed, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) self::fail('INVALID_PROJECT_FIELD', 'مقدار یکی از فیلدهای فرم معتبر نیست: ' . $field);
        return $value;
    }

    private static function stringList(mixed $value, array $allowed, int $max, string $field): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) self::fail('INVALID_PROJECT_FIELD', 'فهرست یکی از فیلدهای فرم معتبر نیست: ' . $field);
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || !in_array($item, $allowed, true)) self::fail('INVALID_PROJECT_FIELD', 'مقدار فهرست فرم معتبر نیست: ' . $field);
            if (in_array($item, $result, true)) self::fail('DUPLICATE_PROJECT_FIELD', 'مقدار تکراری در فرم پذیرفته نمی‌شود: ' . $field);
            $result[] = $item;
        }
        return $result;
    }

    private static function customColorList(mixed $value): array
    {
        // Master Handoff §14.4: no free-form CSS or named colors. Alpha is not
        // part of this form's current contract, so only opaque #RRGGBB is valid.
        $maximumCustomColors = 4;
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximumCustomColors) {
            self::fail('INVALID_PROJECT_COLOR', 'حداکثر چهار رنگ دلخواه و فقط با قالب #RRGGBB پذیرفته می‌شود.');
        }

        $out = [];
        foreach ($value as $color) {
            if (!is_string($color)) self::fail('INVALID_PROJECT_COLOR', 'کد رنگ دلخواه باید متن باشد.');
            $color = strtoupper(trim($color));
            if (!preg_match('/^#[A-F0-9]{6}$/D', $color)) {
                self::fail('INVALID_PROJECT_COLOR', 'کد رنگ باید دقیقاً به شکل #RRGGBB باشد؛ نام رنگ و CSS آزاد پذیرفته نیست.');
            }
            if (in_array($color, $out, true)) {
                self::fail('DUPLICATE_PROJECT_FIELD', 'رنگ دلخواه تکراری است.');
            }
            $out[] = $color;
        }
        return $out;
    }

    private static function plainText(mixed $value, int $max, string $field, bool $required): string
    {
        if (!is_string($value)) self::fail('INVALID_PROJECT_FIELD', 'نوع یکی از فیلدهای فرم معتبر نیست: ' . $field);
        $value = trim($value);
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) || self::length($value) > $max || ($required && $value === '')) {
            self::fail('INVALID_PROJECT_FIELD', 'مقدار یکی از فیلدهای فرم معتبر نیست: ' . $field);
        }
        return $value;
    }

    private static function normalizePhone(mixed $value): string
    {
        if (!is_string($value)) self::fail('INVALID_PROJECT_PHONE', 'شماره تماس معتبر نیست.');
        $digits = strtr(trim($value), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        $digits = preg_replace('/[\s().\-]/u', '', $digits) ?? '';
        if (preg_match('/^09[0-9]{9}$/D', $digits)) return '+98' . substr($digits, 1);
        if (preg_match('/^\+989[0-9]{9}$/D', $digits)) return $digits;
        if (preg_match('/^00989[0-9]{9}$/D', $digits)) return '+' . substr($digits, 2);
        self::fail('INVALID_PROJECT_PHONE', 'شماره تماس باید شمارهٔ همراه معتبر ایران باشد.');
    }

    private static function length(string $value): int
    {
        $n = preg_match_all('/./us', $value);
        return $n === false ? PHP_INT_MAX : $n;
    }

    private static function fail(string $code, string $detail): never
    {
        throw new ApiProblemException(422, $code, 'Validation failed', $detail);
    }
}
