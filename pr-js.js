document.addEventListener("DOMContentLoaded", function () {
  const sections = [
    {
      sectionId: "prSection1",
      toggleId: "toggleHeader1",
      cardId: "prCard1",
      title: "ROPS",
      groups: [
        {
          label: "How to create Marketo program for EMC using Power Apps Form",
          questions: [
            {
              id: "1",
              text: "Did the SDS trigger the ROPs manually to automate the request?",
            },
            {
              id: "2",
              text: "ROPS auto and manual triggered failed",
            },
          ],
        },
      ],
    },
    {
      sectionId: "prSection2",
      toggleId: "toggleHeader2",
      cardId: "prCard2",
      title: "NAME-O-MATIC",
      groups: [
        {
          label: "Manual Name-o-matic is needed if the ROPs failed",
          questions: [
            {
              id: "3",
              text: "Did the SDS follow the right Name-o-matic formatting?",
            },
            {
              id: "4",
              text: "Did the SDS follow the right field in creating the Name-o-matic?",
            },
          ],
        },
      ],
    },
    {
      sectionId: "prSection3",
      toggleId: "toggleHeader3",
      cardId: "prCard3",
      title: "MARKETO",
      groups: [
        {
          label: "Marketo Tag",
          questions: [
            {
              id: "5",
              text: "Are the right setup tags populated base on the request?",
            },
          ],
        },
        {
          label: "Tokens",
          questions: [
            {
              id: "6",
              text: "Are the local tokens populated correctly? ",
            },
          ],
        },
        {
          label: "Smart Campaigns",
          questions: [
            {
              id: "7",
              text: "Did the SDS update the Smart List correctly? ",
            },
            {
              id: "8",
              text: "Did the SDS update the Flow tab correctly",
            },
            {
              id: "9",
              text: "Did the SDS activate the necessary campaigns?",
            },
          ],
        },
        {
          label: "SC02 Update Program Status to Invited",
          questions: [
            {
              id: "10",
              text: "Did the SDS update the Smart List correctly? ",
            },
            {
              id: "11",
              text: "Did the SDS update the Flow tab correctly?",
            },
            {
              id: "12",
              text: "Did the SDS activate the necessary campaigns?",
            },
          ],
        },
        {
          label: "SC03 Update Program Status to Attended",
          questions: [
            {
              id: "13",
              text: "Did the SDS update the Smart List correctly? ",
            },
            {
              id: "14",
              text: "Did the SDS update the Flow tab correctly?",
            },
            {
              id: "15",
              text: "Did the SDS activate the necessary campaigns?",
            },
          ],
        },
        {
          label: "SC04 Update Program Status to Rejected",
          questions: [
            {
              id: "16",
              text: "Did the SDS update the Smart List correctly? ",
            },
            {
              id: "17",
              text: "Did the SDS update the Flow tab correctly?",
            },
            {
              id: "18",
              text: "Did the SDS activate the necessary campaigns?",
            },
          ],
        },
        {
          label: "SC05 Update Program Status to Contact Me",
          questions: [
            {
              id: "19",
              text: "Is the Smart List populated for Contact Me Enabled?",
            },
            {
              id: "20",
              text: "Is the Smart Campaign Activated for Contact Me Enabled?",
            },
          ],
        },
        {
          label: "SC06 Activate PELN Disabled",
          questions: [
            {
              id: "21",
              text: "Is the REP ID under Flow Tab populated?",
            },
            {
              id: "22",
              text: "Is the Smart Campaign Activated for PELN Disabled",
            },
          ],
        },
        {
          label: "SC06 Activate PELN Enabled",
          questions: [
            {
              id: "23",
              text: "Is the REP ID under Flow Tab populated?",
            },
            {
              id: "24",
              text: "Is the Smart Campaign Activated for PELN Disabled",
            },
          ],
        },
      ],
    },
    {
      sectionId: "prSection4",
      toggleId: "toggleHeader4",
      cardId: "prCard4",
      title: "ON24",
      groups: [
        {
          label: "ON24 Template",
          questions: [
            {
              id: "25",
              text: "Did the SDS select the correct folder for cloning the template? (MSFT Field Webcast)",
            },
            {
              id: "26",
              text: "Are the Tags, Content, Primary Language of the template correct? (Aux Tool validation)",
            },
          ],
        },
        {
          label: "ON24 Registration Tab",
          questions: [
            {
              id: "27",
              text: "Is the registration page description updated base on the MaSH form? (Local Scale Events)",
            },
            {
              id: "28",
              text: "Did the SDS hyperlinked the EMC Registration URL to the verbiage 'Click here to register' and removed the '[Add link]' text (MVTD Events)",
            },
          ],
        },
        {
          label: "ON24 Console Builder Tab",
          questions: [
            {
              id: "29",
              text: "Did the SDS toggled off the optional widgets i.e Speaker Bio and resource list",
            },
            {
              id: "30",
              text: "Did the resource add/ change the localization of the default survey questions using the Questions library. also did the SDS use the Survey question list in KB?",
            },
          ],
        },
        {
          label: "ON24 Archive Tab",
          questions: [
            {
              id: "31",
              text: "Did the SDS check for the Archive tab if it is set to the correct value?",
            },
          ],
        },
      ],
    },
    {
      sectionId: "prSection5",
      toggleId: "toggleHeader5",
      cardId: "prCard5",
      title: "EMC",
      groups: [
        {
          label: "EMC Plan Tab",
          questions: [
            {
              id: "32",
              text: "Does the event name match with the request?",
            },
            {
              id: "33",
              text: "Does the Owning Area and Owning Subsidiary match with the MaSH request?",
            },
            {
              id: "34",
              text: "Does the Start Date&Time and End Date&Time match with the request?",
            },
            {
              id: "35",
              text: "Does the Solution Area, Marketing Play / Scenario match with the request?",
            },
            {
              id: "36",
              text: "Are all the fields in Event Objective under Plan tab, match with the request?",
            },
          ],
        },
        {
          label: "EMC Registration Content Tab",
          questions: [
            {
              id: "37",
              text: "Is the registration page description updated base on the MaSH form? ",
            },
            {
              id: "38",
              text: "Does the displayed date and time match the request (if required)",
            },
            {
              id: "39",
              text: "Does the Delivery Language and Subtitle Language match the request?",
            },
            {
              id: "40",
              text: "Does the Registration Start Date/Time and End Date/Time match the information provided in the request?",
            },
            {
              id: "41",
              text: "Does the registration capacity match the request?",
            },
          ],
        },
        {
          label: "EMC Agenda Tab",
          questions: [
            {
              id: "42",
              text: "Does the session match the Start & End Date/Time of the event?",
            },
            {
              id: "43",
              text: "Did the SDS toggle the publish status of the session to Registration Live?",
            },
            {
              id: "44",
              text: "Did the SDS populate the ON24 ID and ON24 Audience URL correctly?",
            },
            {
              id: "45",
              text: "Do the speaker details match the request? (If requested)",
            },
          ],
        },
        {
          label: "EMC Build Configuration Tab",
          questions: [
            {
              id: "46",
              text: "Is the ON24 Audience URL correct?",
            },
            {
              id: "47",
              text: "Does the Event Survey match with the language of the event?",
            },
            {
              id: "48",
              text: "Is the Enable Contact Me toggled as 'No'? (If ECM not requested)",
            },
            {
              id: "49",
              text: "Did the SDS populate the Contact Me URL correctly? (If requested)",
            },
            {
              id: "50",
              text: "Is the OnDemand toggled to 'No'? (if OnDemand is not requested)",
            },
            {
              id: "51",
              text: "Is the OnDemand toggled to 'Yes'? (if OnDemand is requested)",
            },
            {
              id: "52",
              text: "Does the OnDemand Start and End Date/Time match with the request? (if OnDemand is requested)",
            },
            {
              id: "53",
              text: "Are the Registration Questions Pre-Approved and/or Custom Questions, entered and populated correctly? (if requested)",
            },
            {
              id: "54",
              text: "Are the Platofrm Unique IDs populated correctly? i.e. Marketo ID and ON24 ID",
            },
          ],
        },
        {
          label: "Banner Text Fields",
          questions: [
            {
              id: "55",
              text: "Is the banner color selection selected base on the request? (Local Scale Events)",
            },
            {
              id: "56",
              text: "Are the banners uploaded correctly to the design studio with the correct name-o-matic?",
            },
            {
              id: "57",
              text: "Is the banner alt text field populated correctly? (Local Scale Events)",
            },
          ],
        },
        {
          label: "Customer Journeys and Segments",
          questions: [
            {
              id: "58",
              text: "Are all the Segments Live?",
            },
          ],
        },
        {
          label: "Customer Journey",
          questions: [
            {
              id: "59",
              text: "Are all the Customer Journeys' status is on Live?",
            },
            {
              id: "60",
              text: "Is the Registration Workflow configured properly with the right cadence?",
            },
            {
              id: "61",
              text: "Is the General Tab on the Registration Workflow configured to T+7?",
            },
            {
              id: "62",
              text: "Is the Waitlist Workflow configured properly? (if waitlist logic is applied)",
            },
            {
              id: "63",
              text: "Is the Waitlist Denied Workflow configured properly?",
            },
            {
              id: "64",
              text: "Is the General Tab on the Waitlist Denied Workflow configured properly? (if waitlist logic is applied)",
            },
            {
              id: "65",
              text: "Is the Registration Denied Workflow configured properly?",
            },
            {
              id: "66",
              text: "Is the Registration OnDemand Workflow configured properly? (if OnDemand is requested)",
            },
          ],
        },
        {
          label: "Marketing Emails",
          questions: [
            {
              id: "67",
              text: "Are all marketing emails generated and on live status?",
            },
            {
              id: "68",
              text: "Are all the Subject Lines for the marketing emails localized based on the primary language of the event?",
            },
          ],
        },
      ],
    },
    {
      sectionId: "prSection6",
      toggleId: "toggleHeader6",
      cardId: "prCard6",
      title: "MASH FORM",
      groups: [
        {
          label: "Deliverables",
          questions: [
            {
              id: "69",
              text: "Is the OA Grid populated correctly and hyperlinks are correct?",
            },
            {
              id: "70",
              text: "Did the SDS attached snips for Registration Page and Registration Workflow?",
            },
          ],
        },
        {
          label: "Supporting Documents",
          questions: [
            {
              id: "71",
              text: "Did the SDS attached a Builder Lead Flow Check and marked as internal?",
            },
            {
              id: "72",
              text: "Did the SDS attached complete email drafts for all marketing emails?",
            },
          ],
        },
      ],
    },
  ];

  const form = document.querySelector("form");

  // Utility to create span elements
  function createSpan(text, className) {
    const span = document.createElement("span");
    span.className = className;
    span.textContent = ` ${text} `;
    return span;
  }

  // Generate sections
  sections.forEach((section) => {
    const container = document.createElement("div");
    container.className = "container";
    container.id = section.sectionId;

    const header = document.createElement("div");
    header.className = "header-row collapsed";
    header.id = section.toggleId;
    header.setAttribute("role", "button");
    header.setAttribute("tabindex", "0");
    header.setAttribute("aria-controls", section.cardId);
    header.setAttribute("aria-expanded", "false");
    header.setAttribute("aria-label", `Toggle ${section.title} section`);

    const h2 = document.createElement("h2");
    h2.textContent = section.title;
    header.appendChild(h2);
    container.appendChild(header);

    const card = document.createElement("div");
    card.className = "card hidden";
    card.id = section.cardId;

    const subheader = document.createElement("div");
    subheader.className = "pr-subheader";

    // Groups (label + questions)
    section.groups.forEach((group) => {
      const labelDiv = document.createElement("div");
      labelDiv.className = "pr-label";
      labelDiv.textContent = group.label;
      subheader.appendChild(labelDiv);

      group.questions.forEach((q) => {
        // Question Text
        const questionDiv = document.createElement("div");
        questionDiv.className = "pr-question-fatal";
        const questionText = document.createElement("div");
        questionText.className = "question";
        questionText.textContent = q.text;
        questionDiv.appendChild(questionText);
        subheader.appendChild(questionDiv);

        // Options
        const optionsDiv = document.createElement("div");
        optionsDiv.className = "options";

        const naOptionsDiv = document.createElement("div");
        naOptionsDiv.className = "na-options";

        ["Applicable", "Not Applicable"].forEach((val) => {
          const optionItem = document.createElement("div");
          optionItem.className = "option-item";

          const label = document.createElement("label");
          label.setAttribute("for", `${val.toLowerCase()}${q.id}`);

          const input = document.createElement("input");
          input.type = "radio";
          input.name = `q${q.id}`;
          input.id = `${val.toLowerCase()}${q.id}`;
          input.value = val;

          const span = document.createElement("span");
          span.textContent = val;

          label.appendChild(input);
          label.appendChild(span);
          optionItem.appendChild(label);
          naOptionsDiv.appendChild(optionItem);
        });

        // Fatality radio options
        const fatalityDiv = document.createElement("div");
        fatalityDiv.className = "fatality";
        fatalityDiv.id = `fatality${q.id}`;

        const nfLabel = document.createElement("label");
        nfLabel.setAttribute("for", `nonFatal${q.id}`);
        const nfInput = document.createElement("input");
        nfInput.type = "radio";
        nfInput.id = `nonFatal${q.id}`;
        nfInput.name = `fatality${q.id}`; // UPDATED here
        nfInput.value = "nonFatal";
        nfLabel.appendChild(nfInput);
        nfLabel.appendChild(createSpan("Non-Fatal", "non-fatal"));

        const fLabel = document.createElement("label");
        fLabel.setAttribute("for", `fatal${q.id}`);
        const fInput = document.createElement("input");
        fInput.type = "radio";
        fInput.id = `fatal${q.id}`;
        fInput.name = `fatality${q.id}`; // UPDATED here
        fInput.value = "fatal";
        fLabel.appendChild(fInput);
        fLabel.appendChild(createSpan("Fatal", "fatal"));

        fatalityDiv.appendChild(nfLabel);
        fatalityDiv.appendChild(fLabel);

        optionsDiv.appendChild(naOptionsDiv);
        optionsDiv.appendChild(fatalityDiv);
        subheader.appendChild(optionsDiv);

        // Proof & Remarks
        const proofDiv = document.createElement("div");
        proofDiv.className = "proof-remarks";
        proofDiv.id = `proof${q.id}`;

        const inputContainer = document.createElement("div");
        inputContainer.className = "input-container";

        const remarksInput = document.createElement("input");
        remarksInput.type = "text";
        remarksInput.id = `remarks${q.id}`;
        remarksInput.name = `remarks${q.id}`; // UPDATED here
        remarksInput.required = true;

        const remarksLabel = document.createElement("label");
        remarksLabel.setAttribute("for", "input");
        remarksLabel.className = "label";
        remarksLabel.textContent = "Remarks";

        const underline = document.createElement("div");
        underline.className = "underline";

        inputContainer.appendChild(remarksInput);
        inputContainer.appendChild(remarksLabel);
        inputContainer.appendChild(underline);

        const imageUpload = document.createElement("input");
        imageUpload.type = "file";
        imageUpload.id = `imageUpload${q.id}`;
        imageUpload.name = `image_q${q.id}[]`; // Important: make it an array for multiple files per question
        imageUpload.accept = "image/*";
        imageUpload.multiple = true;

        proofDiv.appendChild(inputContainer);
        proofDiv.appendChild(imageUpload);

        subheader.appendChild(proofDiv);
      });
    });

    card.appendChild(subheader);
    container.appendChild(card);

    // FIX: Target the specific div inside the main content area
    const sectionsContainer = document.getElementById("form-sections");
    if (sectionsContainer) {
      sectionsContainer.appendChild(container);
    }
  });

  // Show/hide fatality + remarks when applicable selected
  function toggleFatalityVisibility(event) {
    const qId = event.target.name.replace("q", "");
    const fatality = document.getElementById(`fatality${qId}`);
    const proof = document.getElementById(`proof${qId}`);

    if (event.target.value === "Applicable") {
      fatality.classList.add("show");
      proof.classList.add("show");
    } else {
      fatality.classList.remove("show");
      proof.classList.remove("show");
    }
  }

  document.querySelectorAll('input[name^="q"]').forEach((radio) => {
    radio.addEventListener("change", toggleFatalityVisibility);
    toggleFatalityVisibility({ target: radio }); // Initialize state
  });

  // Toggle expand/collapse
  function setupToggle(toggleId, cardId) {
    const header = document.getElementById(toggleId);
    const card = document.getElementById(cardId);

    function toggleCard() {
      const isHidden = card.classList.toggle("hidden");
      header.setAttribute("aria-expanded", !isHidden);
      header.classList.toggle("collapsed", isHidden);
    }

    header.addEventListener("click", toggleCard);
    header.addEventListener("keydown", (e) => {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        toggleCard();
      }
    });
  }

  // Setup toggles
  sections.forEach((section) => {
    setupToggle(section.toggleId, section.cardId);
  });
});

document
  .getElementById("confirmProceedBtn")
  .addEventListener("click", function () {
    // Find the form element
    var form = document.querySelector("form");

    // Make sure the form is ready for submission
    if (form) {
      // Submit the form (this triggers the backend to save data)
      form.submit();
    }

    // Close the modal after submitting the form
    var modal = new bootstrap.Modal(
      document.getElementById("confirmationModal"),
    );
    modal.hide();
  });
document
  .getElementById("confirmProceedBtn")
  .addEventListener("click", function () {
    // Submit the form
    document.getElementById("reviewForm").submit();
  });
