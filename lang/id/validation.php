<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Indonesian translation of Laravel's built-in validator messages. Only
    | the framework's own rule keys live here — per-field display names come
    | from each component's validationAttributes()/'attributes' below, kept
    | separate from the literal-string overrides in lang/id.json.
    |
    */

    'accepted' => ':attribute wajib disetujui.',
    'accepted_if' => ':attribute wajib disetujui apabila :other bernilai :value.',
    'active_url' => ':attribute bukan URL yang valid.',
    'after' => ':attribute harus berupa tanggal setelah :date.',
    'after_or_equal' => ':attribute harus berupa tanggal setelah atau sama dengan :date.',
    'alpha' => ':attribute hanya boleh berisi huruf.',
    'alpha_dash' => ':attribute hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
    'alpha_num' => ':attribute hanya boleh berisi huruf dan angka.',
    'any_of' => ':attribute tidak valid.',
    'array' => ':attribute harus berupa sebuah array.',
    'ascii' => ':attribute hanya boleh berisi karakter alfanumerik dan simbol satu byte.',
    'before' => ':attribute harus berupa tanggal sebelum :date.',
    'before_or_equal' => ':attribute harus berupa tanggal sebelum atau sama dengan :date.',
    'between' => [
        'array' => ':attribute harus memiliki antara :min dan :max item.',
        'file' => ':attribute harus memiliki ukuran antara :min dan :max kilobyte.',
        'numeric' => ':attribute harus berada antara :min dan :max.',
        'string' => ':attribute harus memiliki panjang antara :min dan :max karakter.',
    ],
    'boolean' => ':attribute harus bernilai true atau false.',
    'can' => ':attribute berisi nilai yang tidak diizinkan.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'contains' => ':attribute tidak memiliki nilai yang diwajibkan.',
    'current_password' => 'Kata sandi salah.',
    'date' => ':attribute bukan tanggal yang valid.',
    'date_equals' => ':attribute harus berupa tanggal yang sama dengan :date.',
    'date_format' => ':attribute tidak sesuai dengan format :format.',
    'decimal' => ':attribute harus memiliki :decimal angka desimal.',
    'declined' => ':attribute harus ditolak.',
    'declined_if' => ':attribute harus ditolak apabila :other bernilai :value.',
    'different' => ':attribute dan :other harus berbeda.',
    'digits' => ':attribute harus :digits digit.',
    'digits_between' => ':attribute harus berjumlah antara :min dan :max digit.',
    'dimensions' => ':attribute memiliki dimensi gambar yang tidak valid.',
    'distinct' => ':attribute memiliki nilai yang duplikat.',
    'doesnt_contain' => ':attribute tidak boleh berisi salah satu dari: :values.',
    'doesnt_end_with' => ':attribute tidak boleh diakhiri dengan salah satu dari: :values.',
    'doesnt_start_with' => ':attribute tidak boleh diawali dengan salah satu dari: :values.',
    'email' => ':attribute bukan alamat email yang valid.',
    'encoding' => ':attribute harus menggunakan enkode :encoding.',
    'ends_with' => ':attribute harus diakhiri dengan salah satu dari: :values.',
    'enum' => ':attribute yang dipilih tidak valid.',
    'exists' => ':attribute yang dipilih tidak valid.',
    'extensions' => ':attribute harus memiliki salah satu ekstensi berikut: :values.',
    'file' => ':attribute harus berupa sebuah berkas.',
    'filled' => ':attribute wajib memiliki nilai.',
    'gt' => [
        'array' => ':attribute harus memiliki lebih dari :value item.',
        'file' => ':attribute harus memiliki ukuran lebih besar dari :value kilobyte.',
        'numeric' => ':attribute harus lebih besar dari :value.',
        'string' => ':attribute harus memiliki lebih dari :value karakter.',
    ],
    'gte' => [
        'array' => ':attribute harus memiliki :value item atau lebih.',
        'file' => ':attribute harus memiliki ukuran lebih besar atau sama dengan :value kilobyte.',
        'numeric' => ':attribute harus lebih besar atau sama dengan :value.',
        'string' => ':attribute harus memiliki minimal :value karakter.',
    ],
    'hex_color' => ':attribute harus berupa warna heksadesimal yang valid.',
    'image' => ':attribute harus berupa gambar.',
    'in' => ':attribute yang dipilih tidak valid.',
    'in_array' => ':attribute tidak ada dalam :other.',
    'in_array_keys' => ':attribute harus berisi setidaknya satu dari kunci berikut: :values.',
    'integer' => ':attribute harus berupa bilangan bulat.',
    'ip' => ':attribute harus berupa alamat IP yang valid.',
    'ipv4' => ':attribute harus berupa alamat IPv4 yang valid.',
    'ipv6' => ':attribute harus berupa alamat IPv6 yang valid.',
    'json' => ':attribute harus berupa string JSON yang valid.',
    'list' => ':attribute harus berupa sebuah list.',
    'lowercase' => ':attribute harus menggunakan huruf kecil.',
    'lt' => [
        'array' => ':attribute harus memiliki kurang dari :value item.',
        'file' => ':attribute harus memiliki ukuran lebih kecil dari :value kilobyte.',
        'numeric' => ':attribute harus lebih kecil dari :value.',
        'string' => ':attribute harus memiliki kurang dari :value karakter.',
    ],
    'lte' => [
        'array' => ':attribute tidak boleh memiliki lebih dari :value item.',
        'file' => ':attribute harus memiliki ukuran lebih kecil atau sama dengan :value kilobyte.',
        'numeric' => ':attribute harus lebih kecil atau sama dengan :value.',
        'string' => ':attribute harus memiliki maksimal :value karakter.',
    ],
    'mac_address' => ':attribute harus berupa alamat MAC yang valid.',
    'max' => [
        'array' => ':attribute tidak boleh memiliki lebih dari :max item.',
        'file' => ':attribute tidak boleh lebih besar dari :max kilobyte.',
        'numeric' => ':attribute tidak boleh lebih besar dari :max.',
        'string' => ':attribute tidak boleh lebih dari :max karakter.',
    ],
    'max_digits' => ':attribute tidak boleh memiliki lebih dari :max digit.',
    'mimes' => ':attribute harus berupa berkas bertipe: :values.',
    'mimetypes' => ':attribute harus berupa berkas bertipe: :values.',
    'min' => [
        'array' => ':attribute harus memiliki setidaknya :min item.',
        'file' => ':attribute harus berukuran setidaknya :min kilobyte.',
        'numeric' => ':attribute harus setidaknya :min.',
        'string' => ':attribute harus memiliki setidaknya :min karakter.',
    ],
    'min_digits' => ':attribute harus memiliki setidaknya :min digit.',
    'missing' => ':attribute wajib tidak ada.',
    'missing_if' => ':attribute wajib tidak ada apabila :other bernilai :value.',
    'missing_unless' => ':attribute wajib tidak ada kecuali :other bernilai :value.',
    'missing_with' => ':attribute wajib tidak ada apabila :values ada.',
    'missing_with_all' => ':attribute wajib tidak ada apabila :values semuanya ada.',
    'multiple_of' => ':attribute harus merupakan kelipatan dari :value.',
    'not_in' => ':attribute yang dipilih tidak valid.',
    'not_regex' => 'Format :attribute tidak valid.',
    'numeric' => ':attribute harus berupa angka.',
    'password' => [
        'letters' => ':attribute harus mengandung setidaknya satu huruf.',
        'mixed' => ':attribute harus mengandung setidaknya satu huruf besar dan satu huruf kecil.',
        'numbers' => ':attribute harus mengandung setidaknya satu angka.',
        'symbols' => ':attribute harus mengandung setidaknya satu simbol.',
        'uncompromised' => ':attribute yang dimasukkan pernah muncul dalam kebocoran data. Silakan pilih :attribute yang berbeda.',
    ],
    'present' => ':attribute wajib ada.',
    'present_if' => ':attribute wajib ada apabila :other bernilai :value.',
    'present_unless' => ':attribute wajib ada kecuali :other bernilai :value.',
    'present_with' => ':attribute wajib ada apabila :values ada.',
    'present_with_all' => ':attribute wajib ada apabila :values semuanya ada.',
    'prohibited' => ':attribute dilarang diisi.',
    'prohibited_if' => ':attribute dilarang diisi apabila :other bernilai :value.',
    'prohibited_if_accepted' => ':attribute dilarang diisi apabila :other disetujui.',
    'prohibited_if_declined' => ':attribute dilarang diisi apabila :other ditolak.',
    'prohibited_unless' => ':attribute dilarang diisi kecuali :other berada dalam :values.',
    'prohibits' => ':attribute melarang :other untuk diisi.',
    'regex' => 'Format :attribute tidak valid.',
    'required' => ':attribute wajib diisi.',
    'required_array_keys' => ':attribute harus berisi entri untuk: :values.',
    'required_if' => ':attribute wajib diisi apabila :other bernilai :value.',
    'required_if_accepted' => ':attribute wajib diisi apabila :other disetujui.',
    'required_if_declined' => ':attribute wajib diisi apabila :other ditolak.',
    'required_unless' => ':attribute wajib diisi kecuali :other berada dalam :values.',
    'required_with' => ':attribute wajib diisi apabila :values ada.',
    'required_with_all' => ':attribute wajib diisi apabila :values semuanya ada.',
    'required_without' => ':attribute wajib diisi apabila :values tidak ada.',
    'required_without_all' => ':attribute wajib diisi apabila tidak ada satu pun dari :values yang ada.',
    'same' => ':attribute dan :other harus sama.',
    'size' => [
        'array' => ':attribute harus berisi :size item.',
        'file' => ':attribute harus berukuran :size kilobyte.',
        'numeric' => ':attribute harus sebesar :size.',
        'string' => ':attribute harus sepanjang :size karakter.',
    ],
    'starts_with' => ':attribute harus diawali dengan salah satu dari: :values.',
    'string' => ':attribute harus berupa teks.',
    'timezone' => ':attribute harus berupa zona waktu yang valid.',
    'unique' => ':attribute sudah digunakan.',
    'uploaded' => ':attribute gagal diunggah.',
    'uppercase' => ':attribute harus menggunakan huruf besar.',
    'url' => ':attribute harus berupa URL yang valid.',
    'ulid' => ':attribute harus berupa ULID yang valid.',
    'uuid' => ':attribute harus berupa UUID yang valid.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    */

    'custom' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | Left empty deliberately — every component in this app already supplies
    | its own Indonesian labels via validationAttributes(), which takes
    | precedence over this array. Only add an entry here for a field that
    | has no such override (e.g. a plain $this->validate([...]) call).
    |
    */

    'attributes' => [],

];
