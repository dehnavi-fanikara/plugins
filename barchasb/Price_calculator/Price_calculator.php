<?php
/**
 * Plugin Name: Price calculator
 * Plugin URI: https://fanikara.com
 * Description: محاسبه هزینه خدمت لوله بازکنی
 * Version: 1.0
 * Author: Fanikara (FKC) Javad Absalan
 * Author URI: https://fanikara.com
 * License: GPL2
 */
 // ثبت شورتکد محاسبه گر قیمت لوله بازکنی
function price_calculator_shortcode() {
    ob_start();
    ?>
    <div class="lp-calculator-wrapper">
        <h3>📊 تخمین قیمت لوله بازکنی در تهران</h3>
        <form id="lp-price-form">
            <!-- متراژ فنر -->
            <label>🔧 متراژ فنر (متر):</label>
            <input type="range" id="cable_length" min="1" max="30" value="10" step="1">
            <span id="cable_length_val">10</span> متر

            <!-- تعداد تکنیسین -->
            <label>👥 تعداد تکنیسین:</label>
            <input type="number" id="technicians" min="1" max="5" value="1" step="1">

            <!-- مدت زمان (دقیقه) -->
            <label>⏱️ مدت زمان ارائه خدمات (دقیقه):</label>
            <input type="number" id="duration_mins" min="15" value="30" step="5">

            <!-- زمان ارائه خدمات (۴ حالت) -->
            <label>📅 زمان ارائه خدمات:</label>
            <select id="service_time">
                <option value="weekday_normal">روزهای کاری - ساعات عادی (۷ تا ۲۰)</option>
                <option value="holiday_normal">روزهای تعطیل - ساعات عادی (۷ تا ۲۰)</option>
                <option value="weekday_night">روزهای کاری - شبانه (۲۱ تا ۷)</option>
                <option value="holiday_night">روزهای تعطیل - شبانه (۲۱ تا ۷)</option>
            </select>

            <!-- نوع لوله -->
            <label>🚽 نوع لوله:</label>
            <select id="pipe_type">
                <option value="sink">سینک آشپزخانه</option>
                <option value="toilet">دستشویی</option>
                <option value="main">لوله اصلی فاضلاب</option>
            </select>

            <!-- تجهیزات (چند گزینه با چکباکس) -->
            <label>🛠️ تجهیزات استفاده شده:</label>
            <label><input type="checkbox" class="equipment" value="spring_normal"> فنر معمولی</label>
            <label><input type="checkbox" class="equipment" value="spring_high"> فنر فشار قوی</label>
            <label><input type="checkbox" class="equipment" value="air_pump"> پمپ تراکم هوا</label>
            <label><input type="checkbox" class="equipment" value="waterjet"> واترجت</label>

            <!-- نمایش قیمت نهایی -->
            <div class="lp-price-result">
                <strong>💰 قیمت تخمینی:</strong> <span id="final_price">۰</span> تومان
            </div>
        </form>
        <p class="lp-note">* قیمت نهایی پس از بازدید دقیق تعیین می‌شود.</p>
    </div>

    <script>
        (function($) {
            // تابع محاسبه قیمت (موقت با فرمول ساده برای نمایش UI)
            function calculatePrice() {
                let cable = parseFloat(document.getElementById('cable_length').value);
                let techs = parseInt(document.getElementById('technicians').value);
                let duration = parseInt(document.getElementById('duration_mins').value);
                let serviceTime = document.getElementById('service_time').value;
                let pipeType = document.getElementById('pipe_type').value;

                // گرفتن تجهیزات انتخاب شده
                let equipments = [];
                document.querySelectorAll('.equipment:checked').forEach(function(checkbox) {
                    equipments.push(checkbox.value);
                });

                // ---------- فرمول موقتی برای نمایش UI (بعداً دقیقتر می‌شود) ----------
                let basePrice = 150000; // قیمت پایه تومان
                let cableCost = cable * 5000;
                let techCost = techs * 80000;
                let durationCost = duration * 2000;

                let timeMultiplier = 1.0;
                if(serviceTime === 'holiday_normal') timeMultiplier = 1.3;
                if(serviceTime === 'weekday_night') timeMultiplier = 1.5;
                if(serviceTime === 'holiday_night') timeMultiplier = 2.0;

                let pipeMultiplier = 1.0;
                if(pipeType === 'toilet') pipeMultiplier = 1.2;
                if(pipeType === 'main') pipeMultiplier = 1.6;

                let equipmentCost = 0;
                equipments.forEach(function(eq) {
                    if(eq === 'spring_normal') equipmentCost += 20000;
                    if(eq === 'spring_high') equipmentCost += 45000;
                    if(eq === 'air_pump') equipmentCost += 2000000;
                    if(eq === 'waterjet') equipmentCost += 4000000;
                });

                let total = (basePrice + cableCost + techCost + durationCost + equipmentCost) * timeMultiplier * pipeMultiplier;
                total = Math.round(total);

                document.getElementById('final_price').innerText = total.toLocaleString();
            }

            // attach event listeners
            const inputs = ['cable_length', 'technicians', 'duration_mins', 'service_time', 'pipe_type'];
            inputs.forEach(id => {
                let el = document.getElementById(id);
                if(el) el.addEventListener('input', calculatePrice);
                if(el && el.tagName === 'INPUT' && el.type === 'range') {
                    el.addEventListener('input', function() {
                        document.getElementById('cable_length_val').innerText = this.value;
                    });
                }
            });
            document.querySelectorAll('.equipment').forEach(cb => {
                cb.addEventListener('change', calculatePrice);
            });

            // initial calculation
            calculatePrice();
        })(jQuery);
    </script>

    <style>
        .lp-calculator-wrapper {
            max-width: 500px;
            margin: 20px auto;
            background: #f9f9ff;
            padding: 20px;
            border-radius: 24px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.05);
            font-family: sans-serif;
        }
        .lp-calculator-wrapper label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
            color: #0a2b3e;
        }
        .lp-calculator-wrapper input, .lp-calculator-wrapper select {
            width: 100%;
            padding: 8px 12px;
            margin-top: 5px;
            border: 1px solid #ccc;
            border-radius: 16px;
            font-size: 1rem;
        }
        .lp-calculator-wrapper input[type="range"] {
            padding: 0;
        }
        .lp-calculator-wrapper .equipment {
            width: auto;
            margin-right: 8px;
        }
        .lp-calculator-wrapper .lp-price-result {
            margin-top: 25px;
            background: #e9f0f5;
            padding: 15px;
            border-radius: 20px;
            text-align: center;
            font-size: 1.5rem;
        }
        .lp-calculator-wrapper .lp-note {
            font-size: 0.75rem;
            color: gray;
            text-align: center;
            margin-top: 15px;
        }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('محاسبه_گر_قیمت', 'price_calculator_shortcode');
?>