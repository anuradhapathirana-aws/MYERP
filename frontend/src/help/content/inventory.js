/**
 * User Manual — Inventory chapters: Purchasing, Stock and Inventory Setup.
 * (Sales and Reports are added in a later phase.)
 *
 * Every number format, status, button label and message below was checked
 * against the screens and services; keep them in step when those change.
 */

/* ───────────────────────────── Purchasing ───────────────────────────── */

const purchasing = [
  {
    id: 'pur-purchase-request',
    section: 'purchasing',
    title: 'Purchase Requests',
    keywords: ['PR', 'purchase request', 'requisition', 'request to buy', 'approve', 'reject', 'Ref-', 'reference number', 'pending approval'],
    summary: 'Ask for goods to be bought. Once approved, a buyer turns the request into a purchase order.',
    where: 'Inventory › Purchase Requests',
    route: '/inventory/purchase-requests',
    who: 'View / Create / Edit / Delete / Approve Purchase Requests',
    before: [
      'The products exist in **Inventory Setup › Products**, with their units and colours.',
      'The **Source Location** and **Source Store** (where the goods are needed) are set up.',
    ],
    sketch: {
      title: 'New Purchase Request',
      crumbs: 'Inventory › Purchase Requests › New',
      cards: [
        { title: 'Request Items', n: 1, fields: [
          { label: 'Product', value: 'Navy Cotton Twill', n: 2 },
          { label: 'Color / Variant', value: 'Navy' },
          { label: 'UOM · Qty', value: 'm · 500' },
        ] },
        { title: 'Request Details', n: 3, fields: [
          { label: 'Date', value: '12-11-2026' },
          { label: 'Reference No.', value: 'Ref-0012 (auto)' },
          { label: 'Source Location / Store', value: 'Colombo · Main', wide: true },
        ] },
        { title: 'Target Warehouse Details', fields: [
          { label: 'Required Date', value: '30-11-2026' },
          { label: 'Transport Mode', value: '—' },
        ] },
      ],
      footer: [{ label: 'Status: Draft ▾', n: 4, tone: 'slate' }, { label: 'Save Draft', n: 5 }],
    },
    steps: [
      { text: 'Click **+ New PR**. Items are on the left, details on the right.', n: 1 },
      { text: 'In **Request Items**, search for the product, then pick its **Color / Variant**, **UOM** and **Qty**. The **Stock in Hand** column shows what you already have. Press `Alt+N` for another row.', n: 2 },
      { text: 'Fill **Request Details**: the date, the **Source Location** and **Source Store**, and optionally a purpose, customer and remarks. The **Reference No.** fills itself.', n: 3 },
      { text: 'Leave **Status** on **Draft** while you are still working, or choose **Approved** when the request is final and can be ordered.', n: 4 },
      { text: 'Click **Save Draft** (or **Save**). The request gets a PR number and a reference number.', n: 5 },
      { text: 'A request that shows **Pending Approval** is approved or rejected from the list with the green ✓ or red ✗ button. Rejecting asks for a reason.' },
    ],
    example: {
      scenario: 'The Colombo store needs 500 m of navy twill for a customer order.',
      table: {
        head: ['Field', 'Value'],
        rows: [
          ['PR No / Reference No.', 'PR-2026-0012 / Ref-0012 (both automatic)'],
          ['Item', 'Navy Cotton Twill · Navy · m · 500'],
          ['Source', 'Colombo › Main Store'],
          ['Status', 'Approved'],
        ],
      },
      result: 'The request is ready for the buyer. When a purchase order is created from it, it becomes **Completed**.',
    },
    statuses: {
      main: ['Draft', 'Approved', 'Completed'],
      side: [
        { from: 'Pending Approval', to: 'Approved', note: 'Approve button' },
        { from: 'Pending Approval', to: 'Rejected', note: 'with a reason' },
        { from: 'Draft', to: 'Cancelled' },
      ],
      states: [
        { name: 'Draft', meaning: 'Still being written. Can be edited or deleted.' },
        { name: 'Pending Approval', meaning: 'Waiting for someone with the Approve permission.' },
        { name: 'Approved', meaning: 'Ready to order. Still editable until a PO is made from it.' },
        { name: 'Completed', meaning: 'A purchase order has been created from it.' },
        { name: 'Rejected', meaning: 'Turned down; the reason is shown on the request.' },
        { name: 'Cancelled', meaning: 'No longer needed.' },
      ],
      auto: 'Approved → Completed happens by itself when a PO is created from the request.',
    },
    rules: [
      'You cannot type the **Reference No.**. It is given at save time (Ref-0001, Ref-0002 …) and never changes.',
      'Only **Draft** or **Approved** requests can be edited, and only **Draft** requests can be deleted.',
      'A purchase order can only be loaded from an **Approved** request.',
      'The **Required Date** cannot be before the request date.',
    ],
    tips: [
      'If two rows have the same product and colour, the screen suggests merging them. One line per product and colour keeps the PO tidy.',
    ],
    related: ['pur-purchase-order', 'flow-purchase-to-pay', 'gs-numbers'],
  },

  {
    id: 'pur-purchase-order',
    section: 'purchasing',
    title: 'Purchase Orders',
    keywords: ['PO', 'purchase order', 'order to supplier', 'buy', 'supplier order', 'consignment', 'payment terms', 'sent to supplier', 'partially received'],
    summary: 'The order you send to a supplier: what you are buying, how much, and at what price.',
    where: 'Inventory › Purchase Orders',
    route: '/inventory/purchase-orders',
    who: 'View / Create / Edit / Delete Purchase Orders',
    before: [
      'The supplier exists in **Master Data › Suppliers**.',
      'If you are ordering from a request, the request is **Approved**.',
    ],
    sketch: {
      title: 'New Purchase Order',
      crumbs: 'Inventory › Purchase Orders › New',
      cards: [
        { title: 'Order Details', n: 1, fields: [
          { label: 'PO Number', value: 'PO-2026-0041' },
          { label: 'Transaction Date', value: '12-11-2026' },
          { label: 'Location / Store', value: 'Colombo · Main' },
          { label: 'From PR (optional)', value: 'PR-2026-0012', n: 2 },
        ] },
        { title: 'Supplier Details', n: 3, fields: [
          { label: 'Supplier', value: 'Lanka Textiles' },
          { label: 'Contact Person / Phone', value: 'Mr Perera · 077…' },
          { label: 'Billing Address', value: 'Colombo 10', wide: true },
        ] },
      ],
      table: { n: 4, cols: ['Product', 'Color', 'Qty', 'Unit', 'Unit Price', 'Disc%', 'Amount'], rows: [['Navy Cotton Twill', 'Navy', '500', 'm', '850.00', '0', '425,000.00']] },
      footer: [{ label: 'Status: Sent to Supplier ▾', n: 5, tone: 'slate' }, { label: 'Create PO', n: 6 }],
    },
    steps: [
      { text: 'Click **+ New PO** and fill **Order Details**: date, location and store, payment terms and the expected delivery date.', n: 1 },
      { text: 'To order what a request asked for, choose it in **From PR (optional)**. Its lines are copied in. Leave it on **Direct PO** to type the lines yourself.', n: 2 },
      { text: 'Choose the **Supplier** and fill the contact person, phone and billing address.', n: 3 },
      { text: 'Check each line: quantity, unit, **Unit Price** and any **Disc%**. The **Net Total** updates as you type.', n: 4 },
      { text: 'Pick a **Status**: **Draft** while preparing, **Sent to Supplier** when it has gone out, **Confirmed** when the supplier accepts it.', n: 5 },
      { text: 'Click **Create PO**. After saving you can **Download PDF** or **Print** it for the supplier.', n: 6 },
    ],
    statuses: {
      main: ['Draft', 'Sent to Supplier', 'Confirmed', 'Partially Received', 'Completed'],
      side: [{ from: 'any open status', to: 'Cancelled' }],
      states: [
        { name: 'Draft', meaning: 'Being prepared. Can be edited or deleted.' },
        { name: 'Sent to Supplier', meaning: 'Sent out. Lines can still be changed.' },
        { name: 'Confirmed', meaning: 'Accepted by the supplier. Goods can now be received.' },
        { name: 'Partially Received', meaning: 'Some goods have arrived on a GRN; more are expected.' },
        { name: 'Completed', meaning: 'Everything ordered has been received.' },
        { name: 'Cancelled', meaning: 'Will not be received.' },
      ],
      auto: 'Partially Received and Completed are set by the system when GRNs are confirmed.',
    },
    rules: [
      'Lines can only be changed while the PO is **Draft** or **Sent to Supplier**.',
      'A GRN can only be raised against a PO that is **Confirmed** or **Partially Received**.',
      'Only **Draft** purchase orders can be deleted. Cancel the others instead.',
      'Creating a PO from a request marks that request **Completed**.',
    ],
    tips: ['Save the PO first; the **Download PDF** and **Print** buttons appear once it has a number.'],
    related: ['pur-purchase-request', 'pur-grn', 'flow-purchase-to-pay'],
  },

  {
    id: 'pur-grn',
    section: 'purchasing',
    title: 'Goods Received Notes (GRN)',
    keywords: ['GRN', 'goods received', 'receive', 'receiving', 'stock in', 'delivery from supplier', 'shipping code', 'contract no', 'multi PO', 'confirm GRN', 'post stock'],
    summary: 'Record what actually arrived from the supplier. Confirming the GRN adds the goods to stock and records what you owe.',
    where: 'Inventory › GRN',
    route: '/inventory/goods-received-notes',
    who: 'View / Create / Edit / Confirm Goods Received Notes',
    before: [
      'The purchase order is **Confirmed** or **Partially Received** (if you receive against a PO).',
      'You have the supplier’s delivery note or invoice in hand, with the roll weights or lengths.',
    ],
    flow: {
      title: 'What a GRN does',
      steps: [
        { label: 'Save as Draft', sub: 'Nothing changes yet', tone: 'slate' },
        { label: 'Confirm', sub: 'Needs “Confirm GRNs”', tone: 'blue' },
        { label: 'Stock in', sub: 'Qty added to the store', tone: 'green' },
        { label: 'PO updated', sub: 'Partially Received / Completed', tone: 'violet' },
        { label: 'Supplier owed', sub: 'Shows on Supplier Payments', tone: 'teal' },
      ],
    },
    sketch: {
      title: 'New GRN',
      crumbs: 'Inventory › GRN › New',
      cards: [
        { title: 'GRN Details', n: 1, fields: [
          { label: 'GRN Date', value: '12-11-2026' },
          { label: 'Supplier Code/Name', value: 'SUP-0004 — Lanka Textiles' },
          { label: 'Location · Store', value: 'Colombo · Main' },
          { label: 'Shipping/Contract No', value: 'SHP-2611', n: 2 },
        ] },
        { title: 'Select Purchase Orders (Optional)', n: 3, fields: [{ label: 'PO', value: '☑ PO-2026-0041', wide: true }] },
      ],
      table: { n: 4, cols: ['Product', 'Color', 'Pending Qty', 'UOM', 'Qty Received', 'Rolls', 'Price', 'Tax'], rows: [['Navy Cotton Twill', 'Navy', '500', 'm', '480', '6 rolls', '850.00', 'VAT (Tax Invoice)']] },
      footer: [{ label: 'Change Status: Confirmed ▾', n: 5, tone: 'slate' }, { label: 'Create & Confirm', n: 6 }],
    },
    steps: [
      { text: 'Click **+ New GRN**. Fill **GRN Details**: dates, supplier, location and store. The **GRN Number** is given automatically.', n: 1 },
      { text: 'Type the **Shipping/Contract No**. It must be unique; the screen tells you straight away if another GRN already uses it.', n: 2 },
      { text: 'Tick one or more of the supplier’s open purchase orders. Their outstanding lines load into **Received Items**. You can also add lines by hand with `Alt+N`.', n: 3 },
      { text: 'For each line enter the **Qty Received**, then the **Rolls** (for roll products), the purchasing price and the **Tax** code. See “Receiving rolls” and “Tax on a GRN”.', n: 4 },
      { text: 'Choose **Draft** to finish later, or **Confirmed** to post the stock now.', n: 5 },
      { text: 'Click **Save as Draft** or **Create & Confirm**. You can also confirm a draft later with the **Confirm & Post Stock** button on the list.', n: 6 },
    ],
    example: {
      scenario: 'PO-2026-0041 ordered 500 m. The supplier delivers 480 m in 6 rolls.',
      table: {
        head: ['', 'Before', 'After confirming GRN-2026-0035'],
        rows: [
          ['Stock of Navy Twill (Main Store)', '120 m', '600 m'],
          ['PO-2026-0041', 'Confirmed', 'Partially Received (20 m pending)'],
          ['Owed to Lanka Textiles', 'Rs 0', 'Rs 408,000 + tax'],
        ],
      },
      result: 'The 6 rolls get their own QR codes and can now be sold. The last price paid is copied to the product as its cost.',
    },
    howItWorks: [
      'Confirming writes one stock movement per line (per batch for batch-tracked products), at the purchasing price converted to the product’s stocking unit.',
      'The PO’s **Partially Received** or **Completed** status is worked out from what is still pending on its lines.',
      'Each line’s price is copied to the product’s price list as the **last cost**. Selling prices are not changed; that is done by a Costing or on the product.',
      'With Finance switched on, the GRN also posts its journal (stock and input VAT against the supplier’s payable).',
      'After confirming, the GRN shows extra buttons: **Print Piece Labels**, **Variant Labels**, and **Create Return** (to send goods back).',
    ],
    statuses: {
      main: ['Draft', 'Confirmed'],
      states: [
        { name: 'Draft', meaning: 'Saved but not in stock. Can be edited or deleted.' },
        { name: 'Confirmed', meaning: 'Stock posted. Locked for good.' },
      ],
    },
    rules: [
      'A confirmed GRN cannot be edited, deleted or un-confirmed. Wrong quantities or weights are corrected with a **Stock Reconciliation**; goods going back use a **Supplier Return**.',
      'Every line of a roll product must have its rolls entered before the GRN can be confirmed.',
      'The total of the roll weights must equal the **Qty Received** for that line.',
      'The **Shipping/Contract No** must be unique across all GRNs.',
    ],
    faq: [
      { q: 'The supplier sent more than we ordered. Can I receive it?', a: 'Yes. Enter what actually arrived. The PO line simply shows nothing pending.' },
      { q: 'One delivery covers two purchase orders.', a: 'Tick both POs in **Select Purchase Orders**. One GRN can receive lines from several POs of the same supplier.' },
      { q: 'I confirmed with a wrong roll weight.', a: 'Raise a **Stock Reconciliation** for that roll with the correct weight. Once approved, stock and the roll are corrected and the GRN itself stays unchanged as a record.' },
    ],
    related: ['pur-grn-rolls', 'pur-grn-tax', 'stk-reconciliation', 'pur-supplier-return', 'pur-costing'],
  },

  {
    id: 'pur-grn-rolls',
    section: 'purchasing',
    title: 'Receiving rolls and printing piece labels',
    keywords: ['rolls', 'roll', 'pieces', 'manage rolls', 'roll weight', 'roll number', 'QR', 'label', 'piece label', 'barcode', 'scan', 'print labels', 'piece code'],
    summary: 'Roll products are received roll by roll. Each roll gets a QR label so it can be found, sold and traced.',
    where: 'Inventory › GRN  ·  Inventory › Print Piece Labels',
    route: '/inventory/piece-labels',
    who: 'View GRNs',
    sketch: {
      title: 'Manage Rolls',
      fields: [
        { label: 'No Of Pieces', value: '6', n: 1 },
        { label: 'Starting Roll No', value: '1001', n: 2 },
        { label: 'Roll 1001 · m', value: '80.5', n: 3 },
        { label: 'Roll 1002 · m', value: '79.5' },
      ],
      footer: [{ label: 'Required 480 · Entered 480 · Balanced ✓', tone: 'green' }, { label: 'Apply', n: 4 }],
    },
    steps: [
      { text: 'On the GRN line, type the **Qty Received** and press `Enter` (or click the **Rolls** cell). **Manage Rolls** opens. Type the **No Of Pieces**; that many roll cards appear.', n: 1 },
      { text: 'Type the **Starting Roll No**. The rolls are numbered from it (1001, 1002 …). You can still change any number.', n: 2 },
      { text: 'Enter each roll’s weight or length. `Enter` moves from roll number to measure to the next roll.', n: 3 },
      { text: 'When the header shows **Balanced ✓**, click **Apply**.', n: 4 },
      { text: 'After the GRN is confirmed, click **Print Piece Labels** on it, or open **Inventory › Print Piece Labels** and filter by GRN No, Shipping Code, Product, Color or **Label Status: Not printed yet**. Then **Print** or download the PDF.' },
      { text: 'Scanning a label with a phone opens the roll’s details: product, shipping code, roll no, weight, store and status. You must be signed in.' },
    ],
    tables: [{
      title: 'How roll codes are built',
      head: ['Code', 'Meaning'],
      rows: [
        ['`GRN-2026-0035-I001-P003`', 'GRN-2026-0035, line 1 of the GRN, piece 3.'],
        ['`…-P003-C1`', 'What is left after roll P003 was cut on a sale (the first cut). A new label is needed.'],
        ['`…-P003-R1`', 'Part of a roll that came back on a customer return.'],
      ],
    }],
    rules: [
      'The rolls of a line must add up exactly to its **Qty Received**. Otherwise you see “Total roll weight … must equal quantity received …”.',
      'Every roll needs a roll number and a weight greater than 0.',
      'Only confirmed GRNs have printable labels.',
      'Quantity-tracked products are received without rolls (the line shows a “no rolls” badge).',
    ],
    tips: [
      'When a roll is cut on a delivery order, the remaining piece needs a new label. Filter **Label Status: Not printed yet** each day to catch them.',
      'Labels that replace an older one are outlined in amber, so you know to swap the label on the roll.',
    ],
    related: ['pur-grn', 'stk-roll-life'],
  },

  {
    id: 'pur-grn-tax',
    section: 'purchasing',
    title: 'Tax on a GRN (VAT, SSCL and imports)',
    keywords: ['tax', 'VAT', 'input VAT', 'SSCL', 'tax invoice', 'no tax invoice', 'import', 'prices include VAT', 'claimable', 'tax code', 'GRN tax'],
    summary: 'Pick the right tax code on each GRN line so claimable VAT is separated from the cost of the goods.',
    where: 'Inventory › GRN › Received Items › Tax column',
    route: '/inventory/goods-received-notes',
    who: 'Create / Edit GRNs',
    tables: [{
      title: 'Which tax code to choose',
      head: ['Situation', 'Choose', 'Effect'],
      rows: [
        ['The supplier gave a valid **VAT Tax Invoice**', 'VAT (Tax Invoice)', 'The VAT is **claimable**. It goes to Input VAT and is not part of the stock cost.'],
        ['No Tax Invoice, or a non-VAT supplier', 'No tax / No Tax Invoice', 'No tax is split out. The whole price is the cost.'],
        ['Foreign supplier', 'Import (taxes paid at Customs)', 'No tax on the GRN. Import VAT and duties go on the **Customs bill** in Finance.'],
        ['Supplier charges SSCL and VAT, with a Tax Invoice', 'SSCL + VAT (Tax Invoice)', 'The SSCL cannot be claimed back, so it is **added to the cost**. The VAT (charged on price + SSCL) is claimable.'],
        ['Goods exempt from VAT', 'Exempt goods', 'No tax.'],
      ],
    }],
    steps: [
      'The **Tax** column fills from the supplier’s **Default Purchase Tax** (set on the supplier). Change it on any line if needed.',
      'If the invoice prices already include VAT, tick **Prices include VAT** above the lines and type the prices as printed. The system splits the VAT out.',
      'Check the **Summary** under the lines: **VAT claimable** (green) and **Tax added to cost** (orange).',
    ],
    rules: [
      '**Prices include VAT** cannot be changed after the GRN is confirmed.',
      'Only active tax codes for purchases appear in the list. They are set up in **Master Data › Tax Codes**.',
    ],
    related: ['md-tax-codes', 'pur-grn', 'md-suppliers'],
  },

  {
    id: 'pur-costing',
    section: 'purchasing',
    title: 'Costings (landed cost and selling prices)',
    keywords: ['costing', 'landed cost', 'FOB', 'CIF', 'margin', 'SSCL', 'VAT', 'selling price', 'expenses', 'freight', 'duty', 'clearance', 'pull bills', 'CST', 'CRef', 'price build up'],
    summary: 'Add freight, duty and other import charges to the supplier price, add your margin, and set the selling prices of a shipment.',
    where: 'Inventory › Costings',
    route: '/inventory/costings',
    who: 'View / Create / Edit / Confirm Costings',
    before: [
      'The shipment’s GRNs are **confirmed**.',
      'If Finance is on: the freight, clearing and customs invoices are entered as **Bills** and linked to the GRNs (optional, but gives the real figures).',
    ],
    flow: {
      title: 'Price build-up (per stocking unit)',
      steps: [
        { label: 'FOB / CIF', sub: 'GRN purchasing price', tone: 'blue' },
        { label: '+ Expenses', sub: 'Typed and/or pulled from bills', tone: 'amber' },
        { label: '+ Margin', tone: 'violet' },
        { label: '+ SSCL', sub: '% of the sum so far, rounded up', tone: 'slate' },
        { label: '= Before-Tax', sub: 'Non-VAT invoices use this', tone: 'teal' },
        { label: '+ VAT = After-Tax', sub: 'VAT invoices use this', tone: 'green' },
      ],
    },
    sketch: {
      title: 'New Costing',
      crumbs: 'Inventory › Costings › New',
      cards: [
        { title: 'Costing Header', n: 1, fields: [
          { label: 'Document No', value: 'CST-2026-0009' },
          { label: 'Supplier', value: 'Asia Fabrics' },
          { label: 'Bill of Lading', value: 'BL-55821' },
        ] },
        { title: 'GRN Selection', n: 2, fields: [{ label: 'GRNs', value: '☑ GRN-2026-0040  ☑ GRN-2026-0041', wide: true }] },
        { title: 'Charges from Bills', n: 3, fields: [{ label: 'Freight bill', value: 'Rs 180,000  [Pull]', wide: true }] },
        { title: 'Pricing Summary', n: 5, fields: [
          { label: 'FOB / CIF', value: 'FOB' },
          { label: 'SSCL %', value: '2.5' },
          { label: 'VAT %', value: '18' },
        ] },
      ],
      table: { n: 4, cols: ['Product', 'FOB', '+Expenses', '+Margin', '+SSCL', 'Before-Tax', 'After-Tax'], rows: [['Navy Twill', '850.00', '120.00', '150.00', '28.00', '1,148.00', '1,354.64']] },
      footer: [{ label: 'Status: Confirmed ▾', tone: 'slate' }, { label: 'Save', n: 6 }],
    },
    steps: [
      { text: 'Click **+ New Costing** and fill the **Costing Header**. Choose the **Supplier** first.', n: 1 },
      { text: 'In **GRN Selection**, tick the shipment’s GRNs. Only confirmed GRNs not yet in a confirmed costing are listed.', n: 2 },
      { text: 'With Finance on, **Charges from Bills** lists bill lines linked to those GRNs. Click **Pull** to add a charge. It is shared over the products by value.', n: 3 },
      { text: 'Open each product row in **Product Cost Build-up** and type the expenses per unit, the **Margin**, and SSCL or VAT % if they differ from the default.', n: 4 },
      { text: 'In **Pricing Summary**, choose **FOB** or **CIF** and check the SSCL and VAT switches. The totals update as you type.', n: 5 },
      { text: 'Save as **Draft** to review, or **Confirmed** to set the prices. Confirming asks “Confirm Costing?” first.', n: 6 },
    ],
    example: {
      scenario: 'Navy twill bought FOB at Rs 850 per m. Expenses Rs 120 per m, margin Rs 150, SSCL 2.5%, VAT 18%.',
      table: {
        head: ['Line', 'Rs per m'],
        rows: [
          ['FOB price', '850.00'],
          ['+ Expenses', '120.00'],
          ['+ Margin', '150.00'],
          ['+ SSCL 2.5% of 1,120.00 = 28.00 → rounded **up**', '28.00'],
          ['= Before-Tax', '1,148.00'],
          ['+ VAT 18%', '206.64'],
          ['= After-Tax (selling price)', '1,354.64'],
        ],
      },
      result: 'On confirm, the landed cost and the selling price are written to the product’s price list. Rolls from this shipment are sold at these prices.',
    },
    tables: [{
      title: 'Expense lists',
      head: ['Type', 'Expenses offered'],
      rows: [
        ['FOB', 'Freight Charges, Custom Duty/SSCL, Bank Charges, Bank Commission, Clearance & Transportation, Insurance'],
        ['CIF', 'Import Customs Duty/SSCL, Clearance & Transportation, Bank Charges, Bank Commission (freight and insurance are already in a CIF price)'],
      ],
    }],
    howItWorks: [
      'All amounts are **per stocking unit** (for example per metre), even if the GRN was received in another unit.',
      'SSCL is a % of FOB/CIF + expenses + margin and is always rounded **up** to the whole rupee.',
      'Both the Before-Tax and the After-Tax price are kept, so VAT and non-VAT invoices each use the right one.',
      'Each roll keeps the price of its own shipment: old stock sells at the old price, new stock at the new one.',
    ],
    statuses: {
      main: ['Draft', 'Confirmed'],
      states: [
        { name: 'Draft', meaning: 'Prices not applied. Can be edited or deleted.' },
        { name: 'Confirmed', meaning: 'Locked. Selling prices are updated.' },
      ],
    },
    rules: [
      'A GRN can be in only **one** confirmed costing. Otherwise: “Cannot confirm: the following GRNs are already costed in another confirmed costing — …”.',
      'A bill charge can be used in only one costing.',
      'A confirmed costing cannot be edited or undone.',
    ],
    related: ['flow-import-landed-cost', 'pur-grn', 'cfg-sales-channels'],
  },

  {
    id: 'pur-supplier-return',
    section: 'purchasing',
    title: 'Supplier Returns (send goods back)',
    keywords: ['supplier return', 'purchase return', 'return to supplier', 'PRN', 'send back', 'damaged', 'wrong item', 'excess', 'quality issue', 'debit note', 'partial roll'],
    summary: 'Send goods from a confirmed GRN back to the supplier. Stock goes down and you owe the supplier less.',
    where: 'Inventory › Supplier Returns',
    route: '/inventory/supplier-returns',
    who: 'View / Create / Edit / Confirm Supplier Returns',
    before: ['The goods were received on a **confirmed** GRN and are still in stock.'],
    sketch: {
      title: 'New Supplier Return',
      crumbs: 'Inventory › Supplier Returns › New',
      cards: [{ title: 'Return Details', n: 1, fields: [
        { label: 'Return No', value: 'PRN-0007 (auto)' },
        { label: 'Supplier', value: 'Lanka Textiles' },
        { label: 'GRN', value: 'GRN-2026-0035', n: 2 },
        { label: 'From Store', value: 'Main' },
      ] }],
      table: { n: 3, cols: ['Item', 'Received', 'Returned', 'Returnable', 'Return Qty', 'Reason'], rows: [['Navy Twill · roll P003', '480', '0', '480', '40', 'Damaged']] },
      footer: [{ label: 'Save Draft', n: 4, tone: 'slate' }, { label: 'Confirm Return', n: 5 }],
    },
    steps: [
      { text: 'Click **+ New Return**, or open the GRN and click **Create Return**, which fills in the supplier and GRN for you.', n: 1 },
      { text: 'Choose the **Supplier**, then the **GRN**, and the store the goods leave from.', n: 2 },
      { text: 'For each line going back, enter the **Return Qty** (or tick the rolls and the amount from each), and choose a **Reason**: Damaged, Wrong Item, Excess, Quality Issue or Other.', n: 3 },
      { text: 'Click **Save Draft** (`Ctrl+Enter`) to finish later.', n: 4 },
      { text: 'Click **Confirm Return** (`Alt+C`). After confirming you can **Download Note** to send with the goods.', n: 5 },
    ],
    example: {
      scenario: '40 m of a roll from GRN-2026-0035 (cost Rs 850 per m) is damaged.',
      table: {
        head: ['If the GRN is…', 'What happens to the Rs 34,000'],
        rows: [
          ['Not yet paid', 'The GRN’s balance goes down from Rs 408,000 to Rs 374,000.'],
          ['Already paid in full', 'A **Supplier Credit Note** (CN-…) for Rs 34,000 is created, to use on the next payment.'],
        ],
      },
      result: 'Stock goes down by 40 m at the GRN cost. The rest of the roll stays in stock under a new code (…-C1) and needs a new label.',
    },
    statuses: { main: ['Draft', 'Confirmed'] },
    rules: [
      'You can return at most **received − already returned** on confirmed returns.',
      'The return date cannot be before the GRN date.',
      'A roll that has been reserved or sold cannot be returned: “Roll … is no longer in stock …”.',
      'Only draft returns can be edited or deleted; a confirmed return cannot be undone.',
    ],
    related: ['flow-returns', 'pur-supplier-credit-notes', 'pur-supplier-payment'],
  },

  {
    id: 'pur-supplier-payment',
    section: 'purchasing',
    title: 'Supplier Payments',
    keywords: ['supplier payment', 'pay supplier', 'payment', 'PAY', 'cheque', 'bank transfer', 'cash', 'set off', 'setoff', 'advance', 'discount', 'void', 'underfunded', 'overpayment', 'pay from'],
    summary: 'Pay one or more GRNs of a supplier by cash, cheque or transfer, or set off a credit note.',
    where: 'Inventory › Supplier Payments',
    route: '/inventory/supplier-payments',
    who: 'View / Create / Confirm / Void Supplier Payments',
    flow: {
      title: 'Building a payment',
      steps: [
        { label: 'Pending GRNs', sub: 'Pick what to pay', tone: 'blue' },
        { label: 'Payment Summary', sub: 'Amount and discount per GRN', tone: 'slate' },
        { label: 'Set Off', sub: 'Use open credit notes', tone: 'violet' },
        { label: 'Payment Details', sub: 'Cash / cheque / transfer', tone: 'teal' },
        { label: 'Confirm', sub: 'GRNs marked paid', tone: 'green' },
      ],
    },
    steps: [
      'Click **+ New Payment** and choose the **Supplier**. Their **Pending GRNs** load with the outstanding amount of each.',
      'Add the GRNs you are paying to the **Payment Summary**. Change the amount to pay part of a GRN, and enter any **Discount** the supplier gave.',
      'Under **Set Off**, enter an amount against any open credit note (returns, earlier overpayments or advances).',
      'Under **Payment Details**, add how you pay: **Pay Mode** and **Amount**, then the bank and cheque or transfer details. With Finance on, also choose **Pay From**, the cash or bank account the money leaves. Its available balance is shown.',
      'When nothing is left to cover, save. Then confirm the payment from the form or with the ✓ on the list.',
      'To pay ahead of any GRN, tick **Advance** and enter the **Amount**. An advance credit note is created to set off later.',
    ],
    example: {
      scenario: 'Pay GRN-2026-0035 (Rs 374,000 outstanding) using a Rs 34,000 credit note and a cheque.',
      table: {
        head: ['Part', 'Amount (Rs)'],
        rows: [
          ['Total Payable (GRN-2026-0035)', '374,000'],
          ['Set Off: CN-0012 (supplier return)', '− 34,000'],
          ['Payment Details: Cheque 004512', '− 340,000'],
          ['Remaining', '0'],
        ],
      },
      result: 'On confirm the GRN shows as paid, CN-0012 becomes **Exhausted**, and the cheque is recorded.',
    },
    statuses: {
      main: ['Draft', 'Confirmed'],
      side: [{ from: 'Confirmed', to: 'Voided', note: 'with a reason' }],
      states: [
        { name: 'Draft', meaning: 'Can be edited or deleted.' },
        { name: 'Confirmed', meaning: 'GRN balances reduced; credit notes used.' },
        { name: 'Voided', meaning: 'Reversed. The GRNs are owed again. Nothing is deleted.' },
      ],
    },
    howItWorks: [
      'Paying **more** than is owed is allowed: the extra becomes an **Over Payment** credit note.',
      'With Finance on, a cash account without enough money is refused. A bank account without enough money asks you to confirm before it goes overdrawn.',
      'A confirmed payment can only be reversed by **Void** (the red button on the list), which asks why.',
    ],
    rules: [
      'You cannot save a payment that does not cover the full Total Payable: “Payment is underfunded — cover the full Total Payable with Setoffs and/or Payment Details before saving.”',
      'A discount cannot be more than the GRN’s outstanding amount.',
      'A payment whose cheque was returned by the bank cannot be voided; the cheque return already reopened the GRNs.',
      'A payment cannot be voided if a credit note it created has already been used.',
    ],
    related: ['pur-supplier-credit-notes', 'flow-purchase-to-pay', 'ts-error-messages'],
  },

  {
    id: 'pur-supplier-credit-notes',
    section: 'purchasing',
    title: 'Supplier Credit Notes',
    keywords: ['credit note', 'supplier credit', 'CN', 'advance', 'over payment', 'overpayment', 'supplier return credit', 'exhausted', 'open', 'set off'],
    summary: 'Money the supplier owes you back, waiting to be used against a future payment.',
    where: 'Inventory › Supplier Credit Notes',
    route: '/inventory/supplier-credit-notes',
    who: 'View Supplier Credit Notes',
    intro: [
      'Supplier credit notes are **never typed in by hand**. The system creates them, and you use them under **Set Off** on a Supplier Payment.',
    ],
    tables: [{
      head: ['Type', 'Created when'],
      rows: [
        ['Supplier Return', 'A confirmed return is worth more than the GRN still owed (the GRN was already paid).'],
        ['Over Payment', 'A confirmed payment paid more than was owed.'],
        ['Advance', 'An advance payment is confirmed.'],
        ['Customer Return (manual, legacy)', 'Old entries from before Supplier Returns existed.'],
      ],
    }],
    statuses: {
      main: ['Open', 'Exhausted'],
      states: [
        { name: 'Open', meaning: 'Has a remaining balance to use.' },
        { name: 'Exhausted', meaning: 'Fully used.' },
      ],
      auto: 'Becomes Exhausted by itself when its remaining balance reaches zero.',
    },
    related: ['pur-supplier-payment', 'pur-supplier-return'],
  },
]

/* ───────────────────────────── Stock ───────────────────────────── */

const stock = [
  {
    id: 'stk-reconciliation',
    section: 'stock',
    title: 'Stock Reconciliation (correcting stock)',
    keywords: ['stock reconciliation', 'adjustment', 'stock adjustment', 'correct stock', 'stock count', 'wrong weight', 'variance', 'SR-', 'approve', 'counted quantity', 'stocktake'],
    summary: 'Correct a stock quantity or a roll’s weight when it is wrong. A second person approves before stock changes.',
    where: 'Inventory › Stock Reconciliations',
    route: '/inventory/stock-reconciliations',
    who: 'Create / Edit Stock Reconciliations; Approve Stock Reconciliations to approve',
    flow: {
      title: 'Correction with approval',
      steps: [
        { label: 'Draft', sub: 'Enter the correct figure', tone: 'slate' },
        { label: 'Submit for approval', tone: 'amber' },
        { label: 'Approve', sub: 'By an approver', tone: 'blue' },
        { label: 'Stock corrected', sub: 'Difference posted', tone: 'green' },
      ],
      branches: [{ at: 2, label: 'Reject', sub: 'With a reason; nothing changes', tone: 'red' }],
    },
    steps: [
      'Click **+ New Reconciliation**. In **Basic Information** choose the **Product**, **Store** and **Location**, and type a clear **Reason** (for example “Roll weighed wrong at receiving (GRN-2026-0042)”).',
      'If it corrects a GRN, set **Related Document** to GRN and enter the GRN. Tick **Also correct the linked Purchase Order’s received quantity** if the PO should change too.',
      'In **Correction Detail**: for a quantity product, choose the colour or variant and type the **Counted / Actual Quantity**. The **System Qty (live)** is shown beside it. For a roll product, add the rolls and type each one’s **New** weight.',
      'Save, then **Submit for approval**.',
      'An approver opens the list and clicks ✓ **Approve** (or ✗ **Reject** with a reason).',
    ],
    example: {
      scenario: 'Roll …-P004 was recorded as 82 m on the GRN but measures 78 m.',
      table: { head: ['Roll', 'Old', 'New', 'Difference'], rows: [['GRN-2026-0035-I001-P004', '82', '78', '− 4 m']] },
      result: 'After approval, stock goes down by 4 m and the roll shows 78 m. The GRN and its costing stay as they were, and the reconciliation is the record of the change.',
    },
    statuses: {
      main: ['Draft', 'Pending Approval', 'Approved'],
      side: [{ from: 'Pending Approval', to: 'Rejected', note: 'with a reason' }],
    },
    howItWorks: [
      'The difference is worked out **when it is approved**, against the stock at that moment, so the result always lands exactly on the figure you entered.',
      'This is the right way to fix a confirmed GRN or costing, which can never be reopened.',
    ],
    rules: [
      'Only drafts can be edited, deleted or submitted.',
      'Approval is blocked if one of the rolls is no longer in stock (for example it was sold meanwhile).',
      'An approved reconciliation cannot be undone by rejecting it later.',
    ],
    related: ['pur-grn', 'stk-roll-life', 'ts-cant-edit'],
  },

  {
    id: 'stk-units',
    section: 'stock',
    title: 'How units of measure work',
    keywords: ['unit', 'UOM', 'stocking UOM', 'base unit', 'conversion', 'kg', 'g', 'metre', 'yard', 'roll', 'convert', 'no conversion defined'],
    summary: 'Every product is stocked in one unit, but you can buy and sell it in other units of the same kind.',
    flow: {
      title: 'One stock figure, many units',
      steps: [
        { label: 'GRN in Kg', sub: '10 Kg received', tone: 'blue' },
        { label: 'Converted', sub: '× 1000', tone: 'slate' },
        { label: 'Stock in g', sub: '10,000 g (stocking UOM)', tone: 'green' },
        { label: 'Sold in g or Kg', sub: 'Converted back', tone: 'teal' },
      ],
    },
    intro: [
      'The **Stocking UOM** on the product is the unit stock is kept in. Documents may use any unit of the **same unit category** (for example Kg, g and lb are all “Weight”). The system converts using the rates in **Unit Conversions**.',
      'Prices are converted the same way: Rs 250 per Kg is Rs 0.25 per g.',
    ],
    rules: [
      'You cannot convert between categories (metres to kilograms): “Cannot convert … — they belong to different unit categories.”',
      'If a rate is missing you will see “No conversion defined from … to … — set the rate in Unit Conversions.” Ask whoever maintains Inventory Setup to add it.',
      'The Stocking UOM of a product is locked once it has stock.',
    ],
    related: ['cfg-units', 'cfg-products'],
  },

  {
    id: 'stk-roll-life',
    section: 'stock',
    title: 'The life of a roll',
    keywords: ['roll', 'roll status', 'in stock', 'allocated', 'reserved', 'delivered', 'returned', 'cut', 'remnant', 'offcut', 'piece'],
    summary: 'A roll is tracked from the day it arrives until it is sold or sent back.',
    statuses: {
      main: ['In Stock', 'Allocated', 'Delivered'],
      side: [
        { from: 'In Stock', to: 'Returned', note: 'sent back on a Supplier Return' },
        { from: 'Allocated', to: 'In Stock', note: 'sales order line removed or cancelled' },
      ],
      states: [
        { name: 'In Stock', meaning: 'On the shelf and available to sell.' },
        { name: 'Allocated', meaning: 'Reserved on a sales order (from the moment it is saved). Nobody else can sell it.' },
        { name: 'Delivered', meaning: 'Shipped on a confirmed delivery order.' },
        { name: 'Returned', meaning: 'Sent back to the supplier.' },
      ],
    },
    howItWorks: [
      'Rolls are born when the GRN is **confirmed**, with the weight or length entered on the GRN.',
      'When a sale needs only part of a roll, the roll is **cut**. The part sold leaves, and the rest stays **In Stock** with a new code ending in `-C1`, `-C2` …, which needs a new label.',
      'Sales use the **oldest rolls first**, so at most one roll is left partly used.',
      'Weight corrections go through **Stock Reconciliation**.',
    ],
    related: ['pur-grn-rolls', 'stk-reconciliation', 'flow-order-to-cash'],
  },

  {
    id: 'stk-batches',
    section: 'stock',
    title: 'Batches and expiry dates',
    keywords: ['batch', 'lot', 'expiry', 'expiry date', 'manufacturing date', 'batch tracked', 'quarantine', 'on hold', 'assign batches'],
    summary: 'Batch-tracked products record which batch (lot) each quantity came from, with its expiry date.',
    where: 'Inventory › GRN › Batch column',
    route: '/inventory/goods-received-notes',
    before: ['The product has **Batch Tracked** ticked under **Product Options**.'],
    steps: [
      'On the GRN line, after the quantity, open the **Batch** cell. **Assign Batches** opens.',
      'Add one row per batch: **Batch No**, **Quantity**, **Status**, and optionally the supplier’s batch no, **Mfg Date** and **Expiry Date**.',
      'The assigned total must match the received quantity. If you try to confirm while a batch product has no batches, the screen asks you to assign them first.',
    ],
    tables: [{
      head: ['Batch status', 'Meaning'],
      rows: [
        ['Active', 'Normal, can be used.'],
        ['Quarantine', 'Held for checking.'],
        ['On Hold', 'Temporarily not to be used.'],
      ],
    }],
    related: ['pur-grn', 'cfg-products'],
  },
]

/* ───────────────────────────── Inventory Setup ───────────────────────────── */

const setup = [
  {
    id: 'cfg-products',
    section: 'inv-config',
    title: 'Products',
    keywords: ['product', 'item', 'new product', 'product code', 'PRD-', 'stock tracking', 'roll', 'quantity', 'stocking UOM', 'price list', 'reorder', 'allow minus', 'no direct sale', 'batch tracked', 'product options'],
    summary: 'Set up an item you buy or sell: its code, unit, how its stock is tracked, its supplier and its prices.',
    where: 'Inventory › Configuration › Products',
    route: '/inventory/products',
    who: 'View / Create / Edit / Delete Products',
    before: [
      'Its **Category**, **Unit Types** and (if it has colours or sizes) its **Attributes** exist.',
      'At least one **Sales Channel** exists, for the price list.',
    ],
    sketch: {
      title: 'New Product',
      crumbs: 'Inventory › Products › New',
      cards: [
        { title: 'Basic Information', n: 1, fields: [
          { label: 'Product Code', value: 'PRD-0145' },
          { label: 'Product Type', value: 'Fabric' },
          { label: 'Product Name', value: 'Navy Cotton Twill', wide: true },
          { label: 'Stocking UOM', value: 'm', n: 2 },
          { label: 'Stock Tracking', value: 'Roll', n: 3 },
        ] },
        { title: 'Supplier *', fields: [{ label: 'Supplier', value: 'Lanka Textiles' }] },
        { title: 'Price List / Sale Channel', n: 4, fields: [
          { label: 'Channel', value: 'Retail' },
          { label: 'Cost / Selling', value: '850 / 1,354.64' },
        ] },
        { title: 'Product Options', n: 5, fields: [{ label: '☐ Allow Minus Stock  ☐ No Direct Sale  ☐ Batch Tracked', value: '', wide: true }] },
      ],
      footer: [{ label: 'Save', n: 6 }],
    },
    steps: [
      { text: 'Click **+ New Product**. Fill **Basic Information**: code, type, name, display name and category. Add attributes such as Colour under **Product Attributes**.', n: 1 },
      { text: 'Choose the **Stocking UOM**, the unit the stock is kept in. Documents may use any unit of the same category.', n: 2 },
      { text: 'Choose **Stock Tracking**: **Roll** (received and sold as labelled rolls) or **Quantity** (plain quantity). Choosing the type **Fabric** suggests Roll; **Finished Good** suggests Quantity.', n: 3 },
      { text: 'Add a row per **Price List / Sale Channel**: unit, cost price and selling price, plus optional wholesale, minimum and maximum prices and discounts. The first row is the default price.', n: 4 },
      { text: 'Choose the supplier, the reorder settings, and the **Product Options** you need.', n: 5 },
      { text: 'Click **Save**.', n: 6 },
    ],
    tables: [{
      title: 'Product options that change behaviour',
      head: ['Option', 'Effect'],
      rows: [
        ['Allow Minus Stock', 'A delivery order may take the stock below zero for this product.'],
        ['No Direct Sale', 'The product cannot be sold as a hand-typed quantity line on a sales order.'],
        ['Batch Tracked', 'Each GRN line asks for batch numbers and expiry dates.'],
        ['Others (Lock Purchase, Free Issue, Non-Returnable, …)', 'Recorded on the product for reference.'],
      ],
    }],
    rules: [
      'The **Stocking UOM** and **Stock Tracking** are locked once the product has stock (“Locked — stock already recorded.”).',
      'Confirmed GRNs update the **cost price** and confirmed costings update the **selling price** on the price list. You can still change prices here.',
    ],
    related: ['cfg-variants', 'cfg-units', 'cfg-sales-channels', 'stk-units'],
  },

  {
    id: 'cfg-variants',
    section: 'inv-config',
    title: 'Attributes and variants (colour, size, design)',
    keywords: ['attribute', 'attribute type', 'colour', 'color', 'size', 'design', 'variant', 'generate variants', 'SKU', 'variant labels'],
    summary: 'Attributes describe products (Colour: Red, Blue). Variants are the combinations you stock and price separately (Red / M).',
    where: 'Inventory › Configuration › Attribute Types / Attributes',
    route: '/inventory/attributes',
    who: 'View / Create / Edit Attribute Types and Attributes',
    flow: {
      title: 'From attribute to variant',
      steps: [
        { label: 'Attribute Type', sub: 'e.g. Colour, Size', tone: 'slate' },
        { label: 'Attributes', sub: 'e.g. Red, Blue · S, M, L', tone: 'blue' },
        { label: 'Product', sub: 'Pick the values it comes in', tone: 'violet' },
        { label: 'Variants', sub: 'Red / M, Blue / L …', tone: 'green' },
      ],
    },
    steps: [
      'Create the **Attribute Type** (for example Colour) in **Attribute Types**.',
      'Add its values (Red, Blue, Navy) in **Attributes**.',
      'On the product, add the attribute type and its values under **Product Attributes**.',
      'For a **Quantity**-tracked product, the **Variants** card appears. Choose values and click **Add Variant**, or **Generate all** combinations. Each variant can have its own barcode and prices; an empty price means “use the product price”.',
    ],
    rules: [
      'Variants are for **quantity**-tracked products. Roll products use a colour per line.',
      'A variant already used by stock or documents cannot be deleted; it is deactivated instead, and its combination is locked.',
    ],
    tips: ['After confirming a GRN with variant lines, use **Variant Labels** on the GRN to print their labels.'],
    related: ['cfg-products'],
  },

  {
    id: 'cfg-categories',
    section: 'inv-config',
    title: 'Categories',
    keywords: ['category', 'categories', 'product group', 'parent category', 'sub category'],
    summary: 'Group products into categories and sub-categories for searching and reports.',
    where: 'Inventory › Configuration › Categories',
    route: '/inventory/categories',
    who: 'View / Create / Edit Categories',
    steps: [
      'Add a category and type its **Category Name**.',
      'To make a sub-category, choose its **Parent Category**.',
      'Optionally set the industry, company and a **Reference Name** (an internal code or alias).',
    ],
    related: ['cfg-products'],
  },

  {
    id: 'cfg-units',
    section: 'inv-config',
    title: 'Unit Categories, Unit Types and Unit Conversions',
    keywords: ['unit category', 'unit type', 'unit conversion', 'UOM', 'base unit', 'rate', 'reapply standards', 'symbol', 'prefix', 'suffix'],
    summary: 'Define the units you use and how they convert to each other.',
    where: 'Inventory › Configuration › Unit Categories / Unit Types / Unit Conversions',
    route: '/inventory/unit-conversions',
    who: 'View / Create / Edit Unit Categories, Unit Types and Unit Conversions',
    steps: [
      '**Unit Categories:** create the kinds of measure, for example Weight, Length, Count.',
      '**Unit Types:** add each unit with its name, **symbol** (kg, m), category, and whether the symbol goes before or after the number (**Prefix** / **Suffix**).',
      '**Unit Conversions:** choose a category, mark one unit as the **Base Unit**, and type each other unit’s **Rate** as “1 base = X this unit”. Click **Reapply Standards** to fill in the usual rates.',
    ],
    example: {
      scenario: 'Length, with metre as the base unit.',
      table: { head: ['Unit', 'Rate (1 m = …)'], rows: [['m', 'Base (reference = 1)'], ['cm', '100'], ['yd', '1.0936']] },
    },
    rules: ['Changing a rate affects documents saved afterwards. Documents already confirmed keep the rate they used.'],
    related: ['stk-units', 'cfg-products'],
  },

  {
    id: 'cfg-stores',
    section: 'inv-config',
    title: 'Stores and Store Types',
    keywords: ['store', 'warehouse', 'store type', 'location', 'shop', 'godown'],
    summary: 'Stores are the places stock is kept. Each store belongs to a location.',
    where: 'Inventory › Configuration › Stores / Store Types',
    route: '/inventory/stores',
    who: 'View / Create / Edit Stores and Store Types',
    steps: [
      'Create the **Store Types** you need (for example Warehouse, Retail).',
      'Click **+ New Store** and fill **Store Details** (code, name, type and location), then the address, contact and settings.',
    ],
    howItWorks: [
      'Stock is held per **product + store + location**. Every GRN, delivery and return names its store.',
      'Documents always ask for the **location first**; the store list then shows only that location’s stores.',
    ],
    related: ['md-companies'],
  },

  {
    id: 'cfg-customers',
    section: 'inv-config',
    title: 'Customers',
    keywords: ['customer', 'new customer', 'customer code', 'CUS-', 'client', 'buyer', 'billing address', 'shipping address', 'sales team', 'VAT number'],
    summary: 'The people and companies you sell to.',
    where: 'Inventory › Configuration › Customers',
    route: '/inventory/customers',
    who: 'View / Create / Edit Customers',
    steps: [
      'Click **+ New Customer**. The **Customer Code** is given automatically (CUS-0001, CUS-0002 …).',
      'Fill **General** (name, registration and tax numbers), **Contact**, **Billing Address** and **Shipping Address**.',
      'Under **Sales Team**, record the sales person, executive and manager responsible.',
      'Click **Save**.',
    ],
    howItWorks: [
      'The customer code becomes part of each invoice number: CUS-0012 appears as `CUS0012` in `26NOV_CUS0012_0457`.',
    ],
    related: ['gs-numbers', 'flow-order-to-cash'],
  },

  {
    id: 'cfg-sales-channels',
    section: 'inv-config',
    title: 'Sales Channels (price lists)',
    keywords: ['sales channel', 'price list', 'retail', 'wholesale', 'e-commerce', 'selling price', 'channel'],
    summary: 'A sales channel is a price list, for example Retail or Wholesale. Each product has a price per channel.',
    where: 'Inventory › Configuration › Sales Channels',
    route: '/inventory/sales-channels',
    who: 'View / Create / Edit Sales Channels',
    steps: [
      'Add a channel: type its name, choose the type (**Retail**, **Wholesale** or **e-commerce**) and the dates it applies from and to.',
      'On each product, add a price row for the channel under **Price List / Sale Channel**.',
    ],
    rules: ['The **first** price row on a product is its default selling price on sales documents.'],
    related: ['cfg-products', 'pur-costing'],
  },

  {
    id: 'cfg-drivers-vehicles',
    section: 'inv-config',
    title: 'Drivers and Vehicles',
    keywords: ['driver', 'vehicle', 'lorry', 'van', 'delivery', 'transport', 'licence'],
    summary: 'A register of your drivers and vehicles.',
    where: 'Inventory › Configuration › Drivers / Vehicles',
    route: '/inventory/drivers',
    who: 'View / Create / Edit Drivers and Vehicles',
    steps: [
      '**Drivers:** add code, name, ID/NIC, licence number, phone and address.',
      '**Vehicles:** add code, registration number, make and model, capacity, and insurance or engine details if you keep them.',
    ],
    rules: [
      'Delivery orders do not pick from these lists yet. On a delivery order you type the **Delivery Vehicle & Number** and the **Responsible Person**.',
    ],
    related: ['flow-order-to-cash'],
  },
]

export const topics = [...purchasing, ...stock, ...setup]
