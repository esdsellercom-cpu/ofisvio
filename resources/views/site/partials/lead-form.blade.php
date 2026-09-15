@php($sent = session('lead_sent') === 'quote')
@php($hasErrors = $errors->any() && old('kind') !== 'booking')
<section id="teklif" class="wrap" style="margin-top:96px">
    <div style="background:var(--surface-warm);border-radius:var(--r-xl);padding:clamp(28px,4vw,56px);display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:48px;align-items:start">
        <div style="min-width:0">
            <p class="eyebrow">07 — Teklif</p>
            <h2 class="h2" style="max-width:20ch;font-size:clamp(28px,3.4vw,42px)">Formu bırakın, aynı iş günü içinde fiyat gelsin</h2>
            <p class="body-muted" style="margin:22px 0 0;font-size:16.5px;max-width:42ch">
                Ekip büyüklüğünüze göre kat planı, tescil için gereken belge listesi ve net aylık maliyet
                tek e-postada. Pazarlama listesine eklenmezsiniz.
            </p>
            <div class="stack" style="margin-top:30px;gap:10px;font-size:15px;color:#3C3A32">
                <span>· Sözleşme öncesi ödeme yok</span>
                <span>· Sözleşme süresi 1 aydan başlar</span>
                <span>· Belge inceleme aynı iş günü içinde</span>
            </div>
        </div>

        <div class="card" style="border-radius:var(--r-lg);padding:26px;display:block">
            @if ($sent)
                <div style="padding:32px 8px;text-align:center">
                    <span style="width:40px;height:40px;border-radius:99px;background:var(--brand);display:block;margin:0 auto 18px"></span>
                    <div style="font-size:19px;font-weight:600;letter-spacing:-.02em">Talebiniz alındı</div>
                    <p class="body-muted" style="margin:10px auto 0;max-width:34ch">{{ session('lead_summary') }}</p>
                    <a href="#teklif" class="btn btn--ghost" style="margin-top:22px">Yeni talep</a>
                </div>
            @else
                <form method="POST" action="{{ route('site.leads.store') }}" data-guard class="stack" style="gap:14px">
                    @csrf
                    <input type="hidden" name="kind" value="quote">
                    <input type="hidden" name="team_size" data-form-team value="{{ old('team_size', '2-5') }}">
                    <div class="hp" aria-hidden="true"><label>Web sitesi<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

                    @if ($hasErrors)
                        <div class="notice notice--error" role="alert">
                            <span class="notice__dot" aria-hidden="true"></span>
                            <div>
                                <strong style="display:block;margin-bottom:4px">Form gönderilemedi</strong>
                                <ul style="margin:0;padding-left:18px;font-size:14px;color:var(--ink-soft)">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif

                    <label class="field">
                        <span class="label">Ad Soyad</span>
                        <input class="control" type="text" name="name" required maxlength="120"
                               placeholder="Zeynep Aydın" value="{{ old('name') }}"
                               @error('name') aria-invalid="true" @enderror>
                    </label>

                    <div class="grid-auto" style="--min:150px;--gap:14px">
                        <label class="field">
                            <span class="label">E-posta</span>
                            <input class="control" type="email" name="email" required maxlength="190"
                                   placeholder="zeynep@sirket.com" value="{{ old('email') }}"
                                   @error('email') aria-invalid="true" @enderror>
                        </label>
                        <label class="field">
                            <span class="label">Telefon</span>
                            <input class="control" type="tel" name="phone" maxlength="32"
                                   placeholder="0532 000 00 00" value="{{ old('phone') }}">
                        </label>
                    </div>

                    <div class="grid-auto" style="--min:150px;--gap:14px">
                        <label class="field">
                            <span class="label">Lokasyon</span>
                            <select class="control" name="location_id">
                                <option value="">Fark etmez</option>
                                @foreach ($locations as $loc)
                                    <option value="{{ $loc->id }}" @selected(old('location_id') == $loc->id)>{{ $loc->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="field">
                            <span class="label">Çözüm</span>
                            <select class="control" name="solution" data-form-solution>
                                @foreach (config('ofisvio.solution_options') as $opt)
                                    <option value="{{ $opt }}" @selected(old('solution') === $opt)>{{ $opt }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <label class="checkbox-row" style="margin-top:4px">
                        <input type="checkbox" name="kvkk" value="1" required @checked(old('kvkk'))>
                        <span><a href="#" style="color:var(--brand);font-weight:600">KVKK</a> aydınlatma metnini okudum, iletişim kurulmasını onaylıyorum.</span>
                    </label>

                    <button type="submit" class="btn btn--ink btn--block" style="margin-top:8px">Teklif isteyin</button>
                </form>
            @endif
        </div>
    </div>
</section>
