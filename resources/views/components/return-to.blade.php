{{--
    Carried by a form that deletes the document whose page it sits on.

    Once that document is gone its page cannot be returned to, and the previous
    page is where the reader actually wants to be — they came to this document
    from somewhere, and deleting it should put them back there rather than on
    whichever list the document happened to belong to. Opening an invoice from
    the second-hand book and deleting it should not land on the sales history.

    Filled in by app.js from the tab's own history. Left empty with the script
    disabled, and the server falls back to the list, which is where it went
    before this existed.
--}}
<input type="hidden" name="return_to" data-return-to>
