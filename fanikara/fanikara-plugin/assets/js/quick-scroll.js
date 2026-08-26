jQuery(document).ready(function ($) {

/**
 * Quick Scroll Active Button
 */
function quickScroll(config) {


    // فقط در صفحه مربوطه اجرا شود
    if (!$('body').hasClass(config.bodyClass)) {
        return;
    }


    let sections = config.sections;

    let triggerPoint = config.triggerPoint || 0.35;


    let activeStyle = {

        'background-color': '#FF8800',
        'border-color': '#E0E0E0',
        'box-shadow': '0px 1px 4px 4px rgba(230,232,237,.41)',
        'color': '#FFFFFF',
        'fill': '#FFFFFF'

    };



    function resetButtons() {

        sections.forEach(function(id){

            $('a[href="#' + id + '"]').css({

                'background-color':'',
                'border-color':'',
                'box-shadow':'',
                'color':'',
                'fill':''

            });

        });

    }





    function checkActive(){


        // مقدار جداگانه برای هر صفحه
        let scrollPosition = window.scrollY + (window.innerHeight * triggerPoint);


        let active = null;



        sections.forEach(function(id){


            let element = document.getElementById(id);


            if(!element){
                return;
            }


            let rect = element.getBoundingClientRect();


            let top = rect.top + window.scrollY;

            let height = rect.height;


            if(
                scrollPosition >= top &&
                scrollPosition <= (top + height)
            ){

                active = id;

            }


        });



        resetButtons();



        if(active){

            $('a[href="#' + active + '"]').css(activeStyle);

        }


    }





    $(window).on('load', function(){

        checkActive();

    });



    $(window).on('scroll resize', function(){

        checkActive();

    });



    // برای Elementor و Lazy Load
    setTimeout(checkActive,1000);

    setTimeout(checkActive,2500);





    /**
     * اصلاح اسکرول Quick Scroll در موبایل
     * ارتفاع منوی ثابت = 130px
     */
    $('a[href^="#"]').on('click', function(e){

        let targetId = $(this).attr('href');

        // فقط لینک‌هایی که مربوط به سکشن‌های همین Quick Scroll هستند
        if (!targetId || targetId === '#') {
            return;
        }

        let id = targetId.substring(1);

        if (sections.indexOf(id) === -1) {
            return;
        }

        let target = document.getElementById(id);

        if (!target) {
            return;
        }


        // فقط در ریسپانسیو موبایل
        if (window.innerWidth <= 767) {

            e.preventDefault();


            let mobileMenuHeight = 130;


            let targetTop =
                $(target).offset().top - mobileMenuHeight;


            $('html, body').stop().animate({

                scrollTop: targetTop

            }, 500);


        }

    });


}





/**
 * single-content
 */
quickScroll({

    bodyClass:'single-content',

    triggerPoint:0.15,

    sections:[

        'content_qs_fanikara',
        'content_qs_get_help',
        'content_qs_about_service'

    ]

});





/**
 * single-fanikar
 */
quickScroll({

    bodyClass:'single-fanikar',

    triggerPoint:0.23,

    sections:[

        'fnk_order',
        'fnk_about',
        'fnk_gallery',
        'fnk_comments'

    ]

});


});

