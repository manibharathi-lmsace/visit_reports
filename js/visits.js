require(['jquery', "core/fragment", 'core/templates', 'core/yui', 'core/loadingicon'], function($, Fragment, Templates, Y, Loadingicon) {

    "use strict"
    var contextid = document.getElementById('contextid').value;

    var filterSubmited = [];

    document.querySelectorAll('form.visits-report-filter').forEach(element => {
        setupEvent(element);
    });

    document.querySelectorAll('#centralised_reports .mod-toggle .show-more-action').forEach(element => {
        MoreUsersEvent(element);
    });


    document.querySelectorAll('form.visits-report-filter select[name="users"]').forEach(element => {
        UserReportEvent(element);
    });

    document.querySelectorAll('form.visits-report-filter select[name="department"]').forEach(element => {
        UserReportEvent(element, 'department');
    });

    document.querySelectorAll('form.dataformatselector').forEach(element => {
        element.setAttribute('target', '_blank');
    });

   /*  document.querySelectorAll('input[type="checkbox"].form-check-input').forEach(element => {
        element.form
    }) */
    $('body').delegate('input[type="checkbox"][name="enddate[enabled]"]', 'click', function() {
        $(this).closest('form').find('input[type="checkbox"][name="startdate[enabled]"]').trigger('click');
    })

/*     $('.visits-report-element input[type="checkbox"]:not([name="startdate[enabled]"])').show();
    $('.visits-report-element [name="startdate[enabled]"]').hide(); */

    function setupEvent(element) {
        element.addEventListener('submit', (event) => {
            event.preventDefault();
            var formdata = new FormData(element);
            var filterdata = new URLSearchParams(formdata).toString();

            var report = formdata.get('report');
            var userid = formdata.get('users');
            var department = formdata.get('department');
            var courseid = visitspagedata.courseid;
            loadReport({'filterdata': filterdata, 'report':  report, 'courseid' : courseid, 'userid': userid, 'department': department});
            // Set filter intialized.
            filterSubmited[report] = true;
        })
    }

    function MoreUsersEvent(selector) {
        selector.addEventListener('click', (event) => {
            event.preventDefault();
            var course = event.currentTarget.getAttribute("data-course");
            var cm = event.currentTarget.getAttribute("data-cm");
            var modinstance = event.currentTarget.getAttribute("data-modinstance");
            var modname = event.currentTarget.getAttribute("data-modname");
            var showmore = event.currentTarget.getAttribute("data-showmore");
            var args = {
                cm: cm,
                modinstance: modinstance,
                course: course,
                modname: modname,
                showmore: showmore,
            };
            Fragment.loadFragment('block_visitsreport', 'getmoduleinfo', contextid, args).done((html, js) => {
                console.log(html);
                Templates.replaceNode("#mod-toggle-"+cm, html, js);
                document.querySelectorAll('#centralised_reports .mod-toggle .show-more-action').forEach(element => {
                    MoreUsersEvent(element);
                });
            });
        });
    }

    function UserReportEvent(selector, field='users') {

        selector.addEventListener('change', (event) => {

            var formdata = new FormData(selector.form);
            var filterdata = new URLSearchParams(formdata).toString();
            var report = formdata.get('report');
            var courseid = visitspagedata.courseid;

            var params = {'report':  report, 'courseid' : courseid};
            if (field == 'department') {
                var department = formdata.get('department');
                params['department'] = department;
            } else {
                var userid = formdata.get('users');
                params['userid'] = userid;
            }

            if (report in filterSubmited) {
                params['filterdata'] = filterdata;
            }
            loadReport(params, field);
        })
    }

    function loadReport(params, field='') {
        var loadIconElement = '#' + params.report + ' '+ ' form.visits-report-filter';
        Loadingicon.addIconToContainer(loadIconElement);
        Fragment.loadFragment('block_visitsreport', 'getreport', contextid, params).done((html, js) => {
            var newElements = Templates.replaceNode('#'+params.report, html, js);
            if (newElements) {
                console.log(('#' + params.report + ' form.visits-report-filter'));
                var element = document.querySelectorAll('#' + params.report + ' '+ ' form.visits-report-filter')[0];
                setupEvent(element);
                var selector = document.querySelectorAll('#' + params.report + ' '+ ' form.visits-report-filter select[name="users"]');
                console.log(selector.length);
                if (selector.length != 0) {
                    UserReportEvent(selector[0], 'users');
                }

                var selector = document.querySelectorAll('#' + params.report + ' '+ ' form.visits-report-filter select[name="department"]');
                console.log(selector.length);
                if (selector.length != 0) {
                    UserReportEvent(selector[0], 'department');
                }
                document.querySelectorAll('form.dataformatselector').forEach(element => {
                    element.setAttribute('target', '_blank');
                });
            }

        });
    }

});