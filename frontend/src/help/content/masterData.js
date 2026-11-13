/**
 * User Manual — Master Data: shared lists used by every module.
 * Labels and rules checked against the Master Data screens and their services.
 */
export const topics = [
  {
    id: 'md-companies',
    section: 'master-data',
    title: 'Industries, Companies and Locations',
    keywords: ['industry', 'company', 'companies', 'location', 'branch', 'head office', 'site', 'base currency', 'financial year', 'parent location'],
    summary: 'Your organisation’s structure: industries, the companies in them, and the locations (branches, sites) each company works from.',
    where: 'Master Data › Industries / Companies / Locations',
    route: '/inventory/locations',
    who: 'View / Create / Edit Industries, Companies and Locations',
    flow: {
      title: 'How they fit together',
      steps: [
        { label: 'Industry', sub: 'e.g. Textiles', tone: 'slate' },
        { label: 'Company', sub: 'Registration & tax numbers', tone: 'blue' },
        { label: 'Location', sub: 'Branch / site', tone: 'violet' },
        { label: 'Stores', sub: 'Where stock is kept', tone: 'green' },
      ],
    },
    steps: [
      '**Industries:** add the industries your companies belong to.',
      '**Companies:** add each company with its **Company Name**, industry, **Registration No.**, **Tax Reg. No.**, address and emails.',
      '**Locations:** add each branch or site with its **Location Code**, **Location Name**, company, type and (optionally) **Parent Location**, address, contact details and regional settings.',
      'Then create the **Stores** of each location in Inventory Setup.',
    ],
    tips: ['Set these up first. Almost every document asks for a location, and the store list depends on it.'],
    related: ['cfg-stores'],
  },

  {
    id: 'md-employees',
    section: 'master-data',
    title: 'Employees',
    keywords: ['employee', 'staff', 'requestor', 'payee', 'department', 'designation'],
    summary: 'Staff who can be named on documents, for example as a requestor or a payee.',
    where: 'Master Data › Employees',
    route: '/master-data/employees',
    who: 'View / Create / Edit Employees',
    steps: [
      'Add the employee’s **Code** (e.g. EMP-0001), **Name**, **Designation**, **Department**, **Location**, **Mobile** and **Email**.',
      'Untick **Active** when someone leaves.',
    ],
    rules: [
      'Inactive employees are hidden from requestor and payee dropdowns, but old documents keep their name.',
      'An employee here is **not** a login. System users are created in **Team Management**.',
    ],
    related: ['admin-users'],
  },

  {
    id: 'md-suppliers',
    section: 'master-data',
    title: 'Suppliers',
    keywords: ['supplier', 'vendor', 'new supplier', 'SUP-', 'supplier code', 'credit limit', 'credit period', 'default purchase tax', 'tax type', 'cheque name', 'check writer'],
    summary: 'The companies you buy from, with their contacts, bank details, credit terms and default tax.',
    where: 'Master Data › Suppliers',
    route: '/inventory/suppliers',
    who: 'View / Create / Edit Suppliers',
    sketch: {
      title: 'New Supplier',
      crumbs: 'Master Data › Suppliers › New',
      cards: [
        { title: 'Supplier', n: 1, fields: [
          { label: 'Supplier Code', value: 'SUP-0004 (auto)' },
          { label: 'Supplier Name', value: 'Lanka Textiles (Pvt) Ltd', wide: true },
          { label: 'Supplier Group', value: 'Local Fabric' },
        ] },
        { title: 'Tax & Credit', n: 2, fields: [
          { label: 'Tax Type', value: 'VAT' },
          { label: 'Default Purchase Tax', value: 'VAT (Tax Invoice)' },
          { label: 'Credit Period (days)', value: '30' },
          { label: 'Credit Limit', value: '2,000,000' },
        ] },
        { title: 'Bank', n: 3, fields: [
          { label: 'Bank Name · Branch', value: 'Commercial Bank · Kandy' },
          { label: 'Check Writer Name', value: 'Lanka Textiles (Pvt) Ltd' },
        ] },
      ],
      footer: [{ label: 'Save', n: 4 }],
    },
    steps: [
      { text: 'Click **+ New Supplier**. The **Supplier Code** is given automatically (SUP-0001, SUP-0002 …). Type the name, choose the **Supplier Group**, and add contacts and address.', n: 1 },
      { text: 'Set the tax details. **Default Purchase Tax** fills the Tax column of new GRN lines for this supplier. Add the credit period, credit limit and any privileges discount.', n: 2 },
      { text: 'Add the bank details and the **Check Writer Name**, the name to write on cheques.', n: 3 },
      { text: 'Attach any documents (registration, agreements), then click **Save**.', n: 4 },
    ],
    tips: [
      'Get the **Default Purchase Tax** right: **VAT (Tax Invoice)** for VAT-registered local suppliers who give Tax Invoices, **No tax / No Tax Invoice** for others, and **Import** for foreign suppliers.',
    ],
    related: ['md-supplier-groups', 'pur-grn-tax', 'pur-purchase-order'],
  },

  {
    id: 'md-supplier-groups',
    section: 'master-data',
    title: 'Supplier Groups',
    keywords: ['supplier group', 'supplier category', 'utility', 'local', 'foreign', 'group'],
    summary: 'Sort suppliers into groups, for example Local Fabric, Foreign or Utility Providers.',
    where: 'Master Data › Supplier Groups',
    route: '/master-data/supplier-groups',
    who: 'View / Create / Edit Supplier Groups',
    steps: [
      'Add a group with its **Code** (e.g. UTILITY), **Name**, **Description** and **Sort Order**.',
      'Choose the group on each supplier.',
    ],
    rules: ['Inactive groups are hidden from the supplier form dropdown.'],
    related: ['md-suppliers'],
  },

  {
    id: 'md-banks',
    section: 'master-data',
    title: 'Banks and Bank Branches',
    keywords: ['bank', 'bank branch', 'branch code', 'SWIFT', 'bank code'],
    summary: 'The banks and branches chosen on cheques, transfers and bank accounts.',
    where: 'Master Data › Banks / Bank Branches',
    route: '/master-data/banks',
    who: 'View / Create / Edit Banks and Bank Branches',
    steps: [
      '**Banks:** add the **Bank Code** (e.g. 7056), **Bank Name** and head office address.',
      '**Bank Branches:** choose the bank, then add the **Branch Code**, **Branch Name**, **SWIFT Code** and address.',
    ],
    rules: [
      'Branch codes must be unique **within a bank**, not across all banks.',
      'Inactive banks are hidden from bank account dropdowns.',
    ],
    related: ['md-payment-modes'],
  },

  {
    id: 'md-payment-modes',
    section: 'master-data',
    title: 'Payment Modes',
    keywords: ['payment mode', 'pay mode', 'cash', 'cheque', 'card', 'bank transfer', 'set-off', 'settlement'],
    summary: 'The ways money is paid or received, and which details each one needs.',
    where: 'Master Data › Payment Modes',
    route: '/inventory/payment-modes',
    who: 'View / Create / Edit Payment Modes',
    steps: [
      'Add a mode with its **Name**, **Code** and **Type**: Cash, Cheque, Bank Transfer, Card or Set-off.',
      'Tick what it **Requires**: **Bank** details, a **Ref No** (e.g. the cheque number) and/or a **Date**. Those fields are then required on payments and receipts that use it.',
      'Set the **Sort Order** for the dropdown.',
    ],
    example: {
      scenario: 'A typical set-up:',
      table: {
        head: ['Mode', 'Type', 'Requires'],
        rows: [['Cash', 'Cash', '—'], ['Cheque', 'Cheque', 'Bank, Ref No, Date'], ['Online Transfer', 'Bank Transfer', 'Bank, Ref No, Date']],
      },
    },
    rules: ['Inactive modes are hidden from the payment settlement dropdown.'],
    related: ['pur-supplier-payment', 'md-banks'],
  },

  {
    id: 'md-taxes',
    section: 'master-data',
    title: 'Taxes (VAT and SSCL rates)',
    keywords: ['tax', 'taxes', 'VAT', 'SSCL', 'rate', 'tax rate', 'rate change', 'effective from', 'current rate'],
    summary: 'The tax rates the system uses, with the date each rate starts.',
    where: 'Master Data › Taxes',
    route: '/master-data/taxes',
    who: 'View / Create / Edit Taxes',
    steps: [
      'Each row has a **Code**, **Name**, **Type** (VAT, SSCL or Other), **Rate %** and **Effective From** date.',
      'The row marked **Current** is the rate in force today.',
      '**When the government changes a rate**, do not edit the old row. Add a **new row with the same code** and the new **Effective From** date.',
    ],
    example: {
      scenario: 'VAT goes from 18% to 20% on 1 January 2027.',
      table: {
        head: ['Code', 'Rate', 'Effective From', 'Used for'],
        rows: [['VAT', '18%', '01-01-2024', 'Documents dated up to 31-12-2026'], ['VAT', '20%', '01-01-2027', 'Documents dated from 01-01-2027']],
      },
      result: 'Documents already issued keep the rate they were issued with.',
    },
    howItWorks: [
      'The rate for a document is the latest row of that code whose Effective From is on or before the document date.',
      'Switching a row **Inactive** turns the tax off from that date (rate 0); an older rate does not come back.',
      '**SSCL_PUR** is the SSCL suppliers charge on their invoices (normally 2.5%). It is a cost of the goods and separate from your own SSCL on sales.',
    ],
    rules: [
      'Built-in taxes (VAT, SSCL) cannot be deleted or have their code or type changed. Switch them off instead.',
      'A tax can have only one rate starting on a given date.',
    ],
    related: ['md-tax-codes', 'pur-costing'],
  },

  {
    id: 'md-tax-codes',
    section: 'master-data',
    title: 'Tax Codes',
    keywords: ['tax code', 'VAT-IN', 'SSCL-VAT-IN', 'NO-TAX-INV', 'IMPORT', 'EXEMPT', 'VAT category', 'zero rated', 'exempt', 'standard rated', 'claimable', 'compound', 'on top'],
    summary: 'A tax code says which taxes apply to a purchase line, in what order, and whether they can be claimed back.',
    where: 'Master Data › Tax Codes',
    route: '/master-data/tax-codes',
    who: 'View / Create / Edit Taxes',
    tables: [{
      title: 'Built-in codes',
      head: ['Code', 'Name', 'Taxes'],
      rows: [
        ['VAT-IN', 'VAT (Tax Invoice)', 'VAT, claimable'],
        ['SSCL-VAT-IN', 'SSCL + VAT (Tax Invoice)', 'SSCL_PUR (added to cost), then VAT **on top**, claimable'],
        ['NO-TAX-INV', 'No tax / No Tax Invoice', 'None'],
        ['IMPORT', 'Import (taxes paid at Customs)', 'None on the GRN'],
        ['EXEMPT-IN', 'Exempt goods', 'None'],
      ],
    }],
    example: {
      scenario: 'A line of Rs 100,000 with **SSCL + VAT (Tax Invoice)**:',
      table: {
        head: ['Tax', 'Calculation', 'Amount (Rs)', 'Treatment'],
        rows: [
          ['SSCL 2.5%', '2.5% of 100,000', '2,500', 'Added to the cost'],
          ['VAT 18% (on top)', '18% of 102,500', '18,450', 'Claimable as Input VAT'],
        ],
      },
    },
    steps: [
      'To add a code: type a **Code** (capital letters, numbers, - and _), a **Name**, and choose the **VAT Category**: Standard rated, Zero rated, Exempt, No Tax Invoice / out of scope, or Import.',
      'For a standard-rated code, list the **Taxes, in order**. For each, tick **On top of taxes above** if it is calculated on the price plus the taxes above it, and **Claimable** if it can be claimed back as Input VAT.',
      'Set the **Order** (its position in the GRN Tax dropdown) and save.',
    ],
    rules: [
      'Built-in codes can be renamed or switched off, but their taxes are fixed because GRNs and suppliers rely on them.',
      'Untick **Claimable** for SSCL, and for VAT without a valid Tax Invoice.',
    ],
    related: ['pur-grn-tax', 'md-taxes', 'md-suppliers'],
  },
]
