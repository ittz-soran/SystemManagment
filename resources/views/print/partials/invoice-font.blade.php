{{--
    The font the A5 invoice prints in — PROJECT_DOC Section 9, the invoice
    designer: Soran, 2026-10-20, "i want use specific font". The shop's own
    font when it has uploaded none. Sets --invoice-font for what includes it.
--}}
<style>
    @if($fontUrl = App\Http\Controllers\BrandingController::fontUrl())
        @font-face { font-family: "Shop Invoice Font"; src: url("{{ $fontUrl }}"); font-display: block; }
        :root { --invoice-font: "Shop Invoice Font", var(--bs-body-font-family); }
    @else
        :root { --invoice-font: var(--bs-body-font-family); }
    @endif
</style>
