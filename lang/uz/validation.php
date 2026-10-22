<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => ':Attribute maydoni qabul qilinishi kerak.',
    'accepted_if' => ':Attribute maydoni :other :value boʻlganda qabul qilinishi kerak.',
    'active_url' => ':Attribute maydoni haqiqiy URL boʻlishi kerak.',
    'after' => ':Attribute maydoni :date sanasidan keyingi sana boʻlishi kerak.',
    'after_or_equal' => ':Attribute maydoni :date sanasiga teng yoki undan keyingi sana boʻlishi kerak.',
    'alpha' => ':Attribute maydoni faqat harflardan iborat boʻlishi kerak.',
    'alpha_dash' => ':Attribute maydoni faqat harflar, raqamlar, chiziqchalar va pastki chiziqlardan iborat boʻlishi kerak.',
    'alpha_num' => ':Attribute maydoni faqat harflar va raqamlardan iborat boʻlishi kerak.',
    'any_of' => ':Attribute maydoni notoʻgʻri.',
    'array' => ':Attribute maydoni massiv boʻlishi kerak.',
    'array_keys' => ':Attribute maydonida faqat quyidagi kalitlar boʻlishi mumkin: :values.',
    'ascii' => ':Attribute maydoni faqat bir baytli harf, raqam va belgilardan iborat boʻlishi kerak.',
    'base64' => ':Attribute maydoni yaroqli Base64 satri boʻlishi kerak.',
    'before' => ':Attribute maydoni :date sanasidan oldingi sana boʻlishi kerak.',
    'before_or_equal' => ':Attribute maydoni :date sanasiga teng yoki undan oldingi sana boʻlishi kerak.',
    'between' => [
        'array' => ':Attribute maydonidagi elementlar soni :min va :max oraligʻida boʻlishi kerak.',
        'file' => ':Attribute maydonidagi fayl hajmi :min va :max kilobayt oraligʻida boʻlishi kerak.',
        'numeric' => ':Attribute maydoni :min va :max oraligʻida boʻlishi kerak.',
        'string' => ':Attribute maydonidagi belgilar soni :min va :max oraligʻida boʻlishi kerak.',
    ],
    'boolean' => ':Attribute maydoni rost yoki yolgʻon boʻlishi kerak.',
    'can' => ':Attribute maydonida ruxsat etilmagan qiymat bor.',
    'confirmed' => ':Attribute maydoni tasdigʻi mos kelmadi.',
    'contains' => ':Attribute maydonida majburiy qiymat yetishmayapti.',
    'current_password' => 'Parol notoʻgʻri.',
    'date' => ':Attribute maydoni haqiqiy sana boʻlishi kerak.',
    'date_equals' => ':Attribute maydoni :date sanasiga teng boʻlishi kerak.',
    'date_format' => ':Attribute maydoni :format formatiga mos kelishi kerak.',
    'decimal' => ':Attribute maydonida verguldan keyin :decimal ta raqam boʻlishi kerak.',
    'declined' => ':Attribute maydoni rad etilishi kerak.',
    'declined_if' => ':Attribute maydoni :other :value boʻlganda rad etilishi kerak.',
    'different' => ':Attribute va :other maydonlari bir-biridan farq qilishi kerak.',
    'digits' => ':Attribute maydoni :digits xonali son boʻlishi kerak.',
    'digits_between' => ':Attribute maydonidagi raqamlar soni :min va :max oraligʻida boʻlishi kerak.',
    'dimensions' => ':Attribute maydonidagi rasm oʻlchamlari notoʻgʻri.',
    'distinct' => ':Attribute maydonida takroriy qiymat bor.',
    'doesnt_contain' => ':Attribute maydonida quyidagilarning hech biri boʻlmasligi kerak: :values.',
    'doesnt_end_with' => ':Attribute maydoni quyidagilardan biri bilan tugamasligi kerak: :values.',
    'doesnt_start_with' => ':Attribute maydoni quyidagilardan biri bilan boshlanmasligi kerak: :values.',
    'email' => ':Attribute maydoni haqiqiy elektron pochta manzili boʻlishi kerak.',
    'encoding' => ':Attribute maydoni :encoding kodlashida boʻlishi kerak.',
    'ends_with' => ':Attribute maydoni quyidagilardan biri bilan tugashi kerak: :values.',
    'enum' => 'Tanlangan :attribute notoʻgʻri.',
    'exists' => 'Tanlangan :attribute notoʻgʻri.',
    'extensions' => ':Attribute maydonidagi fayl quyidagi kengaytmalardan biriga ega boʻlishi kerak: :values.',
    'file' => ':Attribute maydoni fayl boʻlishi kerak.',
    'filled' => ':Attribute maydoni boʻsh boʻlmasligi kerak.',
    'gt' => [
        'array' => ':Attribute maydonidagi elementlar soni :value dan koʻp boʻlishi kerak.',
        'file' => ':Attribute maydonidagi fayl hajmi :value kilobaytdan katta boʻlishi kerak.',
        'numeric' => ':Attribute maydoni :value dan katta boʻlishi kerak.',
        'string' => ':Attribute maydonidagi belgilar soni :value dan koʻp boʻlishi kerak.',
    ],
    'gte' => [
        'array' => ':Attribute maydonidagi elementlar soni kamida :value ta boʻlishi kerak.',
        'file' => ':Attribute maydonidagi fayl hajmi kamida :value kilobayt boʻlishi kerak.',
        'numeric' => ':Attribute maydoni :value dan katta yoki unga teng boʻlishi kerak.',
        'string' => ':Attribute maydonidagi belgilar soni kamida :value ta boʻlishi kerak.',
    ],
    'hex_color' => ':Attribute maydoni haqiqiy oʻn oltilik rang kodi boʻlishi kerak.',
    'image' => ':Attribute maydoni rasm boʻlishi kerak.',
    'in' => 'Tanlangan :attribute notoʻgʻri.',
    'in_array' => ':Attribute maydoni :other ichida mavjud boʻlishi kerak.',
    'in_array_keys' => ':Attribute maydonida quyidagi kalitlardan kamida bittasi boʻlishi kerak: :values.',
    'integer' => ':Attribute maydoni butun son boʻlishi kerak.',
    'ip' => ':Attribute maydoni haqiqiy IP manzil boʻlishi kerak.',
    'ipv4' => ':Attribute maydoni haqiqiy IPv4 manzil boʻlishi kerak.',
    'ipv6' => ':Attribute maydoni haqiqiy IPv6 manzil boʻlishi kerak.',
    'json' => ':Attribute maydoni haqiqiy JSON satri boʻlishi kerak.',
    'list' => ':Attribute maydoni roʻyxat boʻlishi kerak.',
    'lowercase' => ':Attribute maydoni kichik harflarda boʻlishi kerak.',
    'lt' => [
        'array' => ':Attribute maydonidagi elementlar soni :value dan kam boʻlishi kerak.',
        'file' => ':Attribute maydonidagi fayl hajmi :value kilobaytdan kichik boʻlishi kerak.',
        'numeric' => ':Attribute maydoni :value dan kichik boʻlishi kerak.',
        'string' => ':Attribute maydonidagi belgilar soni :value dan kam boʻlishi kerak.',
    ],
    'lte' => [
        'array' => ':Attribute maydonidagi elementlar soni koʻpi bilan :value ta boʻlishi kerak.',
        'file' => ':Attribute maydonidagi fayl hajmi koʻpi bilan :value kilobayt boʻlishi kerak.',
        'numeric' => ':Attribute maydoni :value dan kichik yoki unga teng boʻlishi kerak.',
        'string' => ':Attribute maydonidagi belgilar soni koʻpi bilan :value ta boʻlishi kerak.',
    ],
    'mac_address' => ':Attribute maydoni haqiqiy MAC manzil boʻlishi kerak.',
    'max' => [
        'array' => ':Attribute maydonidagi elementlar soni koʻpi bilan :max ta boʻlishi kerak.',
        'file' => ':Attribute maydonidagi fayl hajmi :max kilobaytdan oshmasligi kerak.',
        'numeric' => ':Attribute maydoni :max dan katta boʻlmasligi kerak.',
        'string' => ':Attribute maydonidagi belgilar soni koʻpi bilan :max ta boʻlishi kerak.',
    ],
    'max_digits' => ':Attribute maydonidagi raqamlar soni koʻpi bilan :max ta boʻlishi kerak.',
    'mimes' => ':Attribute maydonidagi fayl quyidagi turlardan biri boʻlishi kerak: :values.',
    'mimetypes' => ':Attribute maydonidagi fayl quyidagi turlardan biri boʻlishi kerak: :values.',
    'min' => [
        'array' => ':Attribute maydonidagi elementlar soni kamida :min ta boʻlishi kerak.',
        'file' => ':Attribute maydonidagi fayl hajmi kamida :min kilobayt boʻlishi kerak.',
        'numeric' => ':Attribute maydoni kamida :min boʻlishi kerak.',
        'string' => ':Attribute maydonidagi belgilar soni kamida :min ta boʻlishi kerak.',
    ],
    'min_digits' => ':Attribute maydonidagi raqamlar soni kamida :min ta boʻlishi kerak.',
    'missing' => ':Attribute maydoni boʻlmasligi kerak.',
    'missing_if' => ':Attribute maydoni :other :value boʻlganda boʻlmasligi kerak.',
    'missing_unless' => ':Attribute maydoni faqat :other :value boʻlgandagina mavjud boʻlishi mumkin.',
    'missing_with' => ':Attribute maydoni :values mavjud boʻlganda boʻlmasligi kerak.',
    'missing_with_all' => ':Attribute maydoni :values mavjud boʻlganda boʻlmasligi kerak.',
    'multiple_of' => ':Attribute maydoni :value ga karrali boʻlishi kerak.',
    'not_in' => 'Tanlangan :attribute notoʻgʻri.',
    'not_regex' => ':Attribute maydoni formati notoʻgʻri.',
    'numeric' => ':Attribute maydoni son boʻlishi kerak.',
    'password' => [
        'letters' => ':Attribute maydonida kamida bitta harf boʻlishi kerak.',
        'mixed' => ':Attribute maydonida kamida bitta katta va bitta kichik harf boʻlishi kerak.',
        'numbers' => ':Attribute maydonida kamida bitta raqam boʻlishi kerak.',
        'symbols' => ':Attribute maydonida kamida bitta maxsus belgi boʻlishi kerak.',
        'uncompromised' => 'Kiritilgan :attribute maʼlumotlar sizib chiqishida uchragan. Iltimos, boshqa :attribute tanlang.',
    ],
    'present' => ':Attribute maydoni mavjud boʻlishi kerak.',
    'present_if' => ':Attribute maydoni :other :value boʻlganda mavjud boʻlishi kerak.',
    'present_unless' => ':Attribute maydoni :other :value boʻlmaganda mavjud boʻlishi kerak.',
    'present_with' => ':Attribute maydoni :values mavjud boʻlganda mavjud boʻlishi kerak.',
    'present_with_all' => ':Attribute maydoni :values mavjud boʻlganda mavjud boʻlishi kerak.',
    'prohibited' => ':Attribute maydoni taqiqlangan.',
    'prohibited_if' => ':Attribute maydoni :other :value boʻlganda taqiqlangan.',
    'prohibited_if_accepted' => ':Attribute maydoni :other qabul qilinganda taqiqlangan.',
    'prohibited_if_declined' => ':Attribute maydoni :other rad etilganda taqiqlangan.',
    'prohibited_unless' => ':Attribute maydoni :other :values ichida boʻlmaganda taqiqlangan.',
    'prohibits' => ':Attribute maydoni toʻldirilganda :other maydoni boʻlmasligi kerak.',
    'regex' => ':Attribute maydoni formati notoʻgʻri.',
    'required' => ':Attribute maydoni toʻldirilishi shart.',
    'required_array_keys' => ':Attribute maydonida quyidagilar uchun qiymatlar boʻlishi kerak: :values.',
    'required_if' => ':Attribute maydoni :other :value boʻlganda toʻldirilishi shart.',
    'required_if_accepted' => ':Attribute maydoni :other qabul qilinganda toʻldirilishi shart.',
    'required_if_declined' => ':Attribute maydoni :other rad etilganda toʻldirilishi shart.',
    'required_unless' => ':Attribute maydoni :other :values ichida boʻlmaganda toʻldirilishi shart.',
    'required_with' => ':Attribute maydoni :values mavjud boʻlganda toʻldirilishi shart.',
    'required_with_all' => ':Attribute maydoni :values mavjud boʻlganda toʻldirilishi shart.',
    'required_without' => ':Attribute maydoni :values mavjud boʻlmaganda toʻldirilishi shart.',
    'required_without_all' => ':Attribute maydoni :values maydonlarining hech biri mavjud boʻlmaganda toʻldirilishi shart.',
    'same' => ':Attribute maydoni :other bilan mos kelishi kerak.',
    'size' => [
        'array' => ':Attribute maydonida :size ta element boʻlishi kerak.',
        'file' => ':Attribute maydonidagi fayl hajmi :size kilobayt boʻlishi kerak.',
        'numeric' => ':Attribute maydoni :size ga teng boʻlishi kerak.',
        'string' => ':Attribute maydoni :size ta belgidan iborat boʻlishi kerak.',
    ],
    'starts_with' => ':Attribute maydoni quyidagilardan biri bilan boshlanishi kerak: :values.',
    'string' => ':Attribute maydoni satr boʻlishi kerak.',
    'timezone' => ':Attribute maydoni haqiqiy vaqt mintaqasi boʻlishi kerak.',
    'unique' => 'Bunday :attribute allaqachon mavjud.',
    'uploaded' => ':Attribute faylini yuklab boʻlmadi.',
    'uppercase' => ':Attribute maydoni katta harflarda boʻlishi kerak.',
    'url' => ':Attribute maydoni haqiqiy URL boʻlishi kerak.',
    'ulid' => ':Attribute maydoni haqiqiy ULID boʻlishi kerak.',
    'uuid' => ':Attribute maydoni haqiqiy UUID boʻlishi kerak.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [
        'email' => 'elektron pochta',
        'locale' => 'til',
        'name' => 'ism',
        'password' => 'parol',
        'password_confirmation' => 'parol tasdigʻi',
    ],

];
