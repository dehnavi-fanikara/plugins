شبه کد زیر رو سعی کردم شبیه به سینتکس جاوااسکریپت بنویسم:

<script>
حالا من به صورت شبه کد بهت فرمول محاسبات قیمت نهایی رو میدم. فرض بر این هست که نهایتا قراره مقدار 
final_price
توی خروجی چاپ بشه برای کاربر:

// تعریف متغیرهای اولیه
var masonry_cost = 'هزینه بنایی به عوامل زیادی بستگی دارد و با توجه به اجرت استاد بنا، کارگر، مصالح و ... حدودا بین 17 تا 80 میلیون تومان است.';
var negotiable_price = 'در شرایط بالا، هزینه کار توافقی است.';
var spring_cost_per_meter = '100000';
var service_cost_per_minute_per_person = '10000';

var weekday_normal_hours_multiplier = 1;
var weekday_night_service_multiplier = 1.4;
var holiday_normal_hours_multiplier = 1.3;
var holiday_night_service_multiplier = 1.82; // 1.3 * 1.4
var vat_rate = 10; // نرخ مالیات بر ارزش افزوده

// -------------------------------------------------------------------------
if (service_type = "بنایی و کنده کاری") { // نمایش حداقل هزینه بنایی
	final_price = masonry_cost;
} else { // شروع محاسبات فرمولی
	// بررسی شرایطی که تعیین قیمت نهایی توافقی است
	if ((equipment_used <> "normal_spring") or (spring_length > 5) or (service_type = "other") or ((problem_cause <> "fecal_blockage") and (problem_cause <> "soft_spongy_objects"))) {
		final_price = negotiable_price;
	} else { // اگه نیازی به بنایی نیست و هزینه هم توافقی نیست و امکان ارائه بازه قیمت وجود دارد
		tax_multiplier = 1;
		if (official_invoice_with_vat = true) {
			tax_multiplier *= 1 + (vat_rate/100);
		}

		final_price_min = ((spring_diameter - 2) / 10) * spring_cost_per_meter * spring_length;
		final_price_min += service_cost_per_minute_per_person * service_duration;
		final_price_min *= service_time;

		final_price_min *= technician_count;

		final_price_min += transportation_cost;
		final_price_min += equipment_rental_cost;
		final_price_min += equipment_transport_cost;
		final_price_min *= tax_multiplier;
		
		final_price_min += tip;
		final_price_max = final_price_min * 1.25;
		final_price = "حدودا بین".final_price_min." تا ".final_price_max." تومان";
	}
} // پایان محاسبات فرمولی
print final_price;
</script>
