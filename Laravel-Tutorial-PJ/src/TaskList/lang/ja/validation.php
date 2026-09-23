<?php

return [
    'required' => ':attributeを入力してください。',
    'string' => ':attributeは文字列で入力してください。',
    'email' => ':attributeは正しいメールアドレスで入力してください。',
    'unique' => 'この:attributeはすでに登録されています。',
    'confirmed' => ':attributeと確認用の入力が一致しません。',
    'min' => ['string' => ':attributeは:min文字以上で入力してください。'],
    'max' => ['string' => ':attributeは:max文字以内で入力してください。'],
    'boolean' => ':attributeの値が不正です。',
    'attributes' => ['name' => '名前', 'email' => 'メールアドレス', 'password' => 'パスワード', 'token' => '再設定トークン'],
];
