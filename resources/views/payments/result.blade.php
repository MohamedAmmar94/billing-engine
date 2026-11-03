<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if ($state === 'confirming')
        <meta http-equiv="refresh" content="5">
    @endif
    <title>Payment status</title>
    <style>
        :root { color-scheme: light dark; }
        body { font-family: system-ui, sans-serif; margin: 0; min-height: 100vh; display: grid; place-items: center; }
        main { max-width: 28rem; padding: 2rem; text-align: center; }
        .button { display: inline-block; padding: .7rem 1.4rem; border-radius: .5rem; background: #2563eb; color: #fff; text-decoration: none; }
    </style>
</head>
<body>
<main>
    @switch($state)
        @case('paid')
            <h1>Payment received</h1>
            <p>Thank you. Invoice {{ $number }} ({{ $amount }}) is paid.</p>
            @break
        @case('confirming')
            <h1>Confirming your payment</h1>
            <p>We are waiting for confirmation from the payment provider. This page refreshes by itself.</p>
            @break
        @case('voided')
            <h1>This invoice was canceled</h1>
            <p>Invoice {{ $number }} is no longer payable. If you were charged, the amount will be refunded.</p>
            @break
        @default
            <h1>Payment not completed</h1>
            <p>No payment was made for invoice {{ $number }} ({{ $amount }}). You can use the same link whenever you are ready.</p>
            <p><a class="button" href="{{ $payUrl }}">Pay now</a></p>
    @endswitch
</main>
</body>
</html>