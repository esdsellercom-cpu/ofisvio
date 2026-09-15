{{-- Şirket durum rozeti — CompanyStatus enum'undan renk seçer. --}}
@php
    use App\Enums\CompanyStatus;
    $tone = match ($status) {
        CompanyStatus::ACTIVE => 'ok',
        CompanyStatus::SUSPENDED, CompanyStatus::TERMINATION_PENDING, CompanyStatus::TERMINATED => 'danger',
        CompanyStatus::KYC_PENDING, CompanyStatus::KYC_REVIEW, CompanyStatus::PAYMENT_PENDING, CompanyStatus::CONTRACT_PENDING => 'warn',
        default => 'info',
    };
@endphp
<span class="badge badge--{{ $tone }}">{{ $status->label() }}</span>
