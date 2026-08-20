/* ==========================================================================
   MITHAAS — Menu catalogue
   --------------------------------------------------------------------------
   Structure only. Every `p` (price) is null until real Mithaas prices are
   supplied — the menu then shows them automatically once
   MITHAAS.showPrices is switched on in config.js.

   Item shape:
     n  name
     d  one-line description
     v  variants (array of strings) — weights for sweets, portions for food
     p  price (number) or an object keyed by variant, e.g. { "250g": 260 }
     t  tag: "signature" | "seasonal" | "new"
     i  image slug -> assets/img/<category folder>/<slug>.jpg
   ========================================================================== */

window.MITHAAS_MENU = [
  {
    id: "traditional-mithai",
    name: "Traditional Mithai",
    short: "Mithai",
    folder: "sweets",
    note: "Sold by weight",
    intro: "The sweets people have grown up on — made in small batches through the day so what you take home was made the same morning.",
    items: [
      { n: "Gulab Jamun", d: "Soft, warm, and soaked right through with cardamom syrup.", v: ["250g", "500g", "1kg"], p: null, t: "signature", i: "gulab-jamun" },
      { n: "Kaju Katli", d: "Thin diamonds of cashew and sugar, finished with silver leaf.", v: ["250g", "500g", "1kg"], p: null, t: "signature", i: "kaju-katli" },
      { n: "Kaju Roll", d: "Rolled cashew with a soft pista centre.", v: ["250g", "500g", "1kg"], p: null, i: "kaju-roll" },
      { n: "Motichoor Laddoo", d: "Tiny pearls of boondi pressed into a laddoo that gives way at first bite.", v: ["250g", "500g", "1kg"], p: null, t: "signature", i: "motichoor-laddoo" },
      { n: "Besan Laddoo", d: "Roasted gram flour and ghee — nutty, grainy, deeply comforting.", v: ["250g", "500g", "1kg"], p: null, i: "besan-laddoo" },
      { n: "Doda Burfi", d: "Slow-cooked till it turns dark and chewy, studded with almonds.", v: ["250g", "500g", "1kg"], p: null, i: "doda-burfi" },
      { n: "Milk Cake", d: "Grainy in the centre, caramelised at the edges.", v: ["250g", "500g", "1kg"], p: null, i: "milk-cake" },
      { n: "Malai Peda", d: "Slow-reduced milk, rolled by hand, lightly spiced.", v: ["250g", "500g", "1kg"], p: null, i: "malai-peda" },
      { n: "Kesar Peda", d: "Saffron-tinted peda with a gentle floral finish.", v: ["250g", "500g", "1kg"], p: null, i: "kesar-peda" },
      { n: "Coconut Burfi", d: "Fresh coconut and milk, kept soft and pale.", v: ["250g", "500g", "1kg"], p: null, i: "coconut-burfi" },
      { n: "Chocolate Burfi", d: "A two-layer burfi the children always reach for first.", v: ["250g", "500g", "1kg"], p: null, i: "chocolate-burfi" },
      { n: "Rasgulla", d: "Spongy chhena in clear, light syrup. Served chilled.", v: ["250g", "500g", "1kg"], p: null, i: "rasgulla" },
      { n: "Rajbhog", d: "A larger rasgulla with a saffron and dry-fruit heart.", v: ["250g", "500g", "1kg"], p: null, i: "rajbhog" },
      { n: "Rasmalai", d: "Chhena discs resting in thickened, cardamom-scented milk.", v: ["4 pc", "8 pc"], p: null, t: "signature", i: "rasmalai" },
      { n: "Rasbhari", d: "Small, soft and syrup-soaked — good by the handful.", v: ["250g", "500g", "1kg"], p: null, i: "rasbhari" },
      { n: "Cham Cham", d: "Oval chhena sweet rolled in coconut, with a malai centre.", v: ["250g", "500g", "1kg"], p: null, i: "cham-cham" },
      { n: "Balushahi", d: "Flaky on the outside, glazed and crisp at the edge.", v: ["250g", "500g", "1kg"], p: null, i: "balushahi" },
      { n: "Imarti", d: "Deep orange spirals, crisp and full of syrup.", v: ["250g", "500g", "1kg"], p: null, i: "imarti" },
      { n: "Jalebi", d: "Fried to order, so it still crackles when you break it.", v: ["250g", "500g", "1kg"], p: null, t: "signature", i: "jalebi" },
      { n: "Sohan Papdi", d: "Layered, flaky, and gone almost as soon as it hits your tongue.", v: ["250g", "500g", "1kg"], p: null, i: "sohan-papdi" },
      { n: "Kalakand", d: "Milk cake with a soft crumb and a clean, milky finish.", v: ["250g", "500g", "1kg"], p: null, i: "kalakand" },
      { n: "Gond Laddoo", d: "Made with edible gum and ghee — a winter staple.", v: ["250g", "500g", "1kg"], p: null, t: "seasonal", i: "gond-laddoo" }
    ]
  },
  {
    id: "premium-sweets",
    name: "Premium Sweets",
    short: "Premium",
    folder: "sweets",
    note: "Gift-ready",
    intro: "Richer sweets, made with more dry fruit and more patience. These are the boxes that go out for weddings, Diwali and the occasions you want to get right.",
    items: [
      { n: "Kaju Anjeer Roll", d: "Cashew wrapped around a fig centre, sliced thin.", v: ["250g", "500g", "1kg"], p: null, t: "signature", i: "kaju-anjeer-roll" },
      { n: "Kaju Kesar Katli", d: "Cashew katli layered with saffron.", v: ["250g", "500g", "1kg"], p: null, i: "kaju-kesar-katli" },
      { n: "Pista Roll", d: "Pistachio, ground fine and rolled with malai.", v: ["250g", "500g", "1kg"], p: null, i: "pista-roll" },
      { n: "Badam Barfi", d: "Almond barfi, dense and unhurried.", v: ["250g", "500g", "1kg"], p: null, i: "badam-barfi" },
      { n: "Anjeer Barfi", d: "Dried figs and nuts, no added sugar beyond the fruit.", v: ["250g", "500g", "1kg"], p: null, i: "anjeer-barfi" },
      { n: "Dry Fruit Laddoo", d: "Dates, almonds and cashew held together without sugar syrup.", v: ["250g", "500g", "1kg"], p: null, i: "dry-fruit-laddoo" },
      { n: "Kesar Malai Roll", d: "Saffron and malai in a soft roll, best eaten cold.", v: ["250g", "500g", "1kg"], p: null, i: "kesar-malai-roll" },
      { n: "Chandrakala", d: "Stuffed with khoya and dry fruit, fried and steeped in syrup.", v: ["250g", "500g", "1kg"], p: null, i: "chandrakala" },
      { n: "Malai Chaap", d: "Delicate layers of chhena and cream.", v: ["250g", "500g", "1kg"], p: null, i: "malai-chaap" },
      { n: "Assorted Premium Box", d: "A mixed selection put together by weight. Tell us the occasion.", v: ["500g", "1kg", "2kg"], p: null, t: "signature", i: "premium-box" }
    ]
  },
  {
    id: "regional-seasonal",
    name: "Regional & Seasonal",
    short: "Regional",
    folder: "sweets",
    note: "Made in season",
    intro: "Sweets that belong to a place or a time of year. Availability changes — call ahead if you are coming for something specific.",
    items: [
      { n: "Ghewar", d: "The monsoon disc of lace-thin batter, soaked and topped with malai.", v: ["Plain", "Malai", "Kesar"], p: null, t: "seasonal", i: "ghewar" },
      { n: "Agra Petha", d: "Translucent, firm, and only lightly sweet.", v: ["250g", "500g", "1kg"], p: null, i: "petha" },
      { n: "Angoori Petha", d: "Small round petha in light syrup.", v: ["250g", "500g", "1kg"], p: null, i: "angoori-petha" },
      { n: "Mysore Pak", d: "Gram flour and ghee, cut while still warm.", v: ["250g", "500g", "1kg"], p: null, i: "mysore-pak" },
      { n: "Bengali Sandesh", d: "Fresh chhena pressed into moulds, barely sweetened.", v: ["250g", "500g"], p: null, i: "sandesh" },
      { n: "Gajar Halwa", d: "Winter carrots cooked down in milk and ghee. Served warm.", v: ["250g", "500g", "1kg"], p: null, t: "seasonal", i: "gajar-halwa" },
      { n: "Moong Dal Halwa", d: "Slow-roasted for hours. Heavy, golden, worth it.", v: ["250g", "500g", "1kg"], p: null, t: "seasonal", i: "moong-dal-halwa" },
      { n: "Sonpapdi Kesar", d: "Saffron sonpapdi, layered and light.", v: ["250g", "500g"], p: null, i: "sonpapdi-kesar" },
      { n: "Malpua", d: "Fried, syrup-dipped, and best with rabri alongside.", v: ["2 pc", "4 pc"], p: null, t: "seasonal", i: "malpua" },
      { n: "Rabri", d: "Milk reduced until it falls in ribbons.", v: ["250g", "500g"], p: null, i: "rabri" }
    ]
  },
  {
    id: "bakery",
    name: "Bakery",
    short: "Bakery",
    folder: "bakery",
    note: "Baked daily",
    intro: "The oven starts early. Breads and cookies come out first, cakes through the day, and whatever is left by evening usually isn't much.",
    items: [
      { n: "Black Forest Pastry", d: "Chocolate sponge, cream and cherry.", v: ["Slice", "500g", "1kg"], p: null, i: "black-forest" },
      { n: "Pineapple Pastry", d: "Light vanilla sponge with fresh pineapple.", v: ["Slice", "500g", "1kg"], p: null, i: "pineapple-cake" },
      { n: "Chocolate Truffle Cake", d: "Dark, dense, and finished with a glossy ganache.", v: ["Slice", "500g", "1kg"], p: null, t: "signature", i: "truffle-cake" },
      { n: "Butterscotch Cake", d: "Praline crunch folded through the cream.", v: ["Slice", "500g", "1kg"], p: null, i: "butterscotch-cake" },
      { n: "Red Velvet Pastry", d: "Cream cheese frosting, soft crumb.", v: ["Slice", "500g", "1kg"], p: null, i: "red-velvet" },
      { n: "Fresh Fruit Gateau", d: "Seasonal fruit over vanilla sponge and cream.", v: ["500g", "1kg"], p: null, i: "fruit-gateau" },
      { n: "Photo & Theme Cakes", d: "Birthdays, anniversaries, office farewells. Order a day ahead.", v: ["500g", "1kg", "2kg"], p: null, i: "theme-cake" },
      { n: "Chocolate Brownie", d: "Fudgy in the middle, crackled on top.", v: ["Piece", "Box of 6"], p: null, i: "brownie" },
      { n: "Doughnuts", d: "Glazed or chocolate-dipped, made fresh each morning.", v: ["Piece", "Box of 6"], p: null, i: "doughnut" },
      { n: "Croissant", d: "Butter croissant, plain or chocolate.", v: ["Plain", "Chocolate"], p: null, i: "croissant" },
      { n: "Puff Patties", d: "Flaky puff with a spiced potato or paneer filling.", v: ["Veg", "Paneer"], p: null, t: "signature", i: "veg-puff" },
      { n: "Pizza Puff", d: "Puff pastry with pizza filling and cheese.", p: null, i: "pizza-puff" },
      { n: "Cookies", d: "Ajwain, jeera, coconut, chocolate chip and butter.", v: ["250g", "500g"], p: null, i: "cookies" },
      { n: "Khari & Toast", d: "Salted khari and rusk — the tea-time regulars.", v: ["250g", "500g"], p: null, i: "khari-toast" },
      { n: "Muffins", d: "Vanilla, chocolate and banana.", v: ["Piece", "Box of 6"], p: null, i: "muffin" },
      { n: "Bread", d: "White, brown and multigrain loaves.", v: ["Loaf"], p: null, i: "bread" },
      { n: "Pav & Buns", d: "Soft pav and burger buns, baked through the day.", v: ["6 pc", "12 pc"], p: null, i: "pav" },
      { n: "Cake Rusk", d: "Twice-baked and crisp. Made for dunking.", v: ["250g", "500g"], p: null, i: "cake-rusk" }
    ]
  },
  {
    id: "north-indian",
    name: "North Indian",
    short: "North Indian",
    folder: "restaurant",
    note: "Served all day",
    intro: "Cooked to order, with gravies started fresh each service. Tell the kitchen how you like your spice.",
    items: [
      { n: "Paneer Butter Masala", d: "Tomato and cashew gravy, finished with butter and cream.", p: null, t: "signature", i: "paneer-butter-masala" },
      { n: "Shahi Paneer", d: "Milder, richer, with a pale golden gravy.", p: null, i: "shahi-paneer" },
      { n: "Kadai Paneer", d: "Peppers, onion and hand-pounded kadai masala.", p: null, i: "kadai-paneer" },
      { n: "Palak Paneer", d: "Spinach cooked down with ginger and garlic.", p: null, i: "palak-paneer" },
      { n: "Matar Paneer", d: "Green peas and paneer in an everyday tomato gravy.", p: null, i: "matar-paneer" },
      { n: "Malai Kofta", d: "Soft koftas in a smooth, gently sweet gravy.", p: null, i: "malai-kofta" },
      { n: "Chole", d: "Chickpeas simmered dark with whole spices.", p: null, i: "chole" },
      { n: "Dal Makhani", d: "Black dal held on low heat overnight.", p: null, t: "signature", i: "dal-makhani" },
      { n: "Dal Fry / Dal Tadka", d: "Yellow dal with a hot ghee and cumin tempering.", v: ["Fry", "Tadka"], p: null, i: "dal-tadka" },
      { n: "Mix Veg", d: "Whatever the market gave us that morning.", p: null, i: "mix-veg" },
      { n: "Aloo Jeera", d: "Potatoes tossed with cumin and green chilli.", p: null, i: "aloo-jeera" },
      { n: "Sev Tamatar", d: "A Rajasthani-style tomato curry finished with sev.", p: null, i: "sev-tamatar" },
      { n: "Tandoori Roti", d: "Plain or buttered, straight off the tandoor.", v: ["Plain", "Butter"], p: null, i: "tandoori-roti" },
      { n: "Naan", d: "Plain, butter or garlic.", v: ["Plain", "Butter", "Garlic"], p: null, i: "naan" },
      { n: "Missi Roti", d: "Gram flour roti with ajwain and onion.", p: null, i: "missi-roti" },
      { n: "Laccha Paratha", d: "Layered, crisp, and made to tear apart.", p: null, i: "laccha-paratha" },
      { n: "Stuffed Paratha", d: "Aloo, paneer, gobhi or mooli. Served with curd and pickle.", v: ["Aloo", "Paneer", "Gobhi", "Mooli"], p: null, t: "signature", i: "stuffed-paratha" },
      { n: "Jeera Rice", d: "Basmati with cumin and ghee.", p: null, i: "jeera-rice" },
      { n: "Veg Pulao", d: "Rice cooked with vegetables and whole spices.", p: null, i: "veg-pulao" },
      { n: "Veg Biryani", d: "Layered and dum-cooked, served with raita.", p: null, i: "veg-biryani" },
      { n: "Boondi Raita", d: "Chilled curd with boondi and roasted jeera.", v: ["Boondi", "Veg", "Pineapple"], p: null, i: "raita" },
      { n: "Green Salad", d: "Onion, cucumber, tomato, lemon.", p: null, i: "salad" },
      { n: "Papad", d: "Roasted or fried, plain or masala.", v: ["Roasted", "Fried", "Masala"], p: null, i: "papad" }
    ]
  },
  {
    id: "south-indian",
    name: "South Indian",
    short: "South Indian",
    folder: "restaurant",
    note: "Batter ground in-house",
    intro: "The batter is ground and left to ferment overnight, which is the whole difference between a good dosa and an ordinary one.",
    items: [
      { n: "Plain Dosa", d: "Thin, crisp, and folded over.", p: null, i: "plain-dosa" },
      { n: "Masala Dosa", d: "Filled with spiced potato and served with sambar and chutney.", p: null, t: "signature", i: "masala-dosa" },
      { n: "Mysore Masala Dosa", d: "Red chutney spread inside before the filling goes in.", p: null, i: "mysore-dosa" },
      { n: "Paneer Dosa", d: "Grated paneer with onion and green chilli.", p: null, i: "paneer-dosa" },
      { n: "Cheese Dosa", d: "For the table that always orders extra cheese.", p: null, i: "cheese-dosa" },
      { n: "Butter Dosa", d: "Cooked slow in butter until deep gold.", p: null, i: "butter-dosa" },
      { n: "Rava Dosa", d: "Lacy and crisp, made with semolina.", v: ["Plain", "Masala", "Onion"], p: null, i: "rava-dosa" },
      { n: "Uttapam", d: "Thick and soft, topped with onion, tomato or mixed veg.", v: ["Onion", "Tomato", "Mixed"], p: null, i: "uttapam" },
      { n: "Idli Sambar", d: "Steamed rice cakes with sambar and coconut chutney.", v: ["2 pc", "4 pc"], p: null, i: "idli" },
      { n: "Medu Vada", d: "Crisp outside, soft inside, with sambar to dip.", v: ["2 pc", "4 pc"], p: null, i: "medu-vada" },
      { n: "Idli Vada Combo", d: "Two idli, one vada, sambar and chutney.", p: null, i: "idli-vada" },
      { n: "South Indian Thali", d: "Idli, vada, dosa, sambar, chutney and a sweet.", p: null, t: "signature", i: "south-thali" },
      { n: "Uppma", d: "Semolina with curry leaf, mustard seed and cashew.", p: null, i: "uppma" },
      { n: "Extra Sambar / Chutney", d: "Because one bowl is never enough.", v: ["Sambar", "Chutney"], p: null, i: "sambar" }
    ]
  },
  {
    id: "snacks-street-food",
    name: "Snacks & Street Food",
    short: "Snacks",
    folder: "restaurant",
    note: "From the chaat counter",
    intro: "The counter at the front, where things are assembled in front of you and eaten standing up more often than not.",
    items: [
      { n: "Samosa", d: "Thick pastry, spiced potato, fried through.", v: ["Plain", "With chole"], p: null, t: "signature", i: "samosa" },
      { n: "Kachori", d: "Pyaaz or dal kachori with tamarind chutney.", v: ["Pyaaz", "Dal"], p: null, i: "kachori" },
      { n: "Samosa Chaat", d: "Broken samosa under chole, curd and chutneys.", p: null, i: "samosa-chaat" },
      { n: "Golgappa / Pani Puri", d: "Six puris, spiced water, and no time to talk.", p: null, t: "signature", i: "golgappa" },
      { n: "Dahi Puri", d: "Filled with potato, curd and sweet chutney.", p: null, i: "dahi-puri" },
      { n: "Bhel Puri", d: "Puffed rice tossed to order so it stays crisp.", p: null, i: "bhel-puri" },
      { n: "Sev Puri", d: "Flat puris, chutneys, and a heavy hand with the sev.", p: null, i: "sev-puri" },
      { n: "Aloo Tikki", d: "Shallow-fried tikki with curd and both chutneys.", p: null, i: "aloo-tikki" },
      { n: "Raj Kachori", d: "One large kachori filled with almost everything.", p: null, i: "raj-kachori" },
      { n: "Papdi Chaat", d: "Crisp papdi, curd, chutney, pomegranate.", p: null, i: "papdi-chaat" },
      { n: "Dahi Bhalla", d: "Soft bhalla in chilled sweetened curd.", p: null, i: "dahi-bhalla" },
      { n: "Pav Bhaji", d: "Bhaji cooked down on the tawa, pav toasted in butter.", p: null, t: "signature", i: "pav-bhaji" },
      { n: "Vada Pav", d: "Batata vada in pav with dry garlic chutney.", p: null, i: "vada-pav" },
      { n: "Chole Bhature", d: "Two bhature, chole, onion and pickle.", p: null, t: "signature", i: "chole-bhature" },
      { n: "Chole Kulcha", d: "Soft kulcha with chole and a wedge of lemon.", p: null, i: "chole-kulcha" },
      { n: "Veg Sandwich", d: "Grilled or plain, with mint chutney.", v: ["Plain", "Grilled", "Cheese"], p: null, i: "sandwich" },
      { n: "Veg Burger", d: "Crumb-fried patty, lettuce and house sauce.", v: ["Veg", "Cheese"], p: null, i: "burger" },
      { n: "Veg Roll", d: "Paneer, veg or aloo rolled into a paratha.", v: ["Veg", "Paneer", "Aloo"], p: null, i: "veg-roll" },
      { n: "French Fries", d: "Salted, peri-peri or cheese.", v: ["Salted", "Peri-peri", "Cheese"], p: null, i: "fries" },
      { n: "Bread Pakoda", d: "Stuffed, battered and fried. A monsoon regular.", p: null, i: "bread-pakoda" },
      { n: "Mix Pakoda", d: "Onion, potato, chilli and paneer.", v: ["250g", "500g"], p: null, i: "mix-pakoda" },
      { n: "Paneer Tikka", d: "Marinated overnight, cooked in the tandoor.", p: null, t: "signature", i: "paneer-tikka" }
    ]
  },
  {
    id: "chinese",
    name: "Chinese",
    short: "Chinese",
    folder: "restaurant",
    note: "Indo-Chinese",
    intro: "Cooked hot and fast in a wok. Ask for it dry or with gravy — the kitchen will do either.",
    items: [
      { n: "Veg Manchurian", d: "Fried vegetable balls, dry or in gravy.", v: ["Dry", "Gravy"], p: null, t: "signature", i: "veg-manchurian" },
      { n: "Paneer Chilli", d: "Paneer, peppers and onion in a sharp soy-chilli sauce.", v: ["Dry", "Gravy"], p: null, t: "signature", i: "chilli-paneer" },
      { n: "Chilli Potato", d: "Crisp potato batons tossed in sweet-hot sauce.", p: null, i: "chilli-potato" },
      { n: "Crispy Corn", d: "Fried corn with pepper, salt and coriander.", p: null, i: "crispy-corn" },
      { n: "Honey Chilli Potato", d: "Sticky, sweet and just hot enough.", p: null, i: "honey-chilli-potato" },
      { n: "Veg Spring Roll", d: "Thin rolls filled with cabbage and noodles.", p: null, i: "spring-roll" },
      { n: "Veg Momos", d: "Steamed or fried, with a red chilli chutney.", v: ["Steamed", "Fried"], p: null, i: "momos" },
      { n: "Paneer Momos", d: "Paneer and spring onion filling.", v: ["Steamed", "Fried"], p: null, i: "paneer-momos" },
      { n: "Hakka Noodles", d: "Wok-tossed with julienned vegetables.", v: ["Veg", "Paneer", "Schezwan"], p: null, i: "hakka-noodles" },
      { n: "Chowmein", d: "The street-side version, cooked on a high flame.", v: ["Veg", "Paneer"], p: null, i: "chowmein" },
      { n: "Fried Rice", d: "Veg, paneer, schezwan or triple.", v: ["Veg", "Paneer", "Schezwan", "Triple"], p: null, i: "fried-rice" },
      { n: "Manchurian Rice Combo", d: "Fried rice with a bowl of manchurian gravy.", p: null, i: "manchurian-rice" },
      { n: "Veg Manchow Soup", d: "Thick, peppery, topped with fried noodles.", p: null, i: "manchow-soup" },
      { n: "Hot & Sour Soup", d: "Sharp with vinegar and white pepper.", p: null, i: "hot-sour-soup" },
      { n: "Sweet Corn Soup", d: "Mild and thick — the table's safe order.", p: null, i: "sweet-corn-soup" }
    ]
  },
  {
    id: "thali-combos",
    name: "Thali & Combos",
    short: "Thali",
    folder: "restaurant",
    note: "Full meals",
    intro: "A full plate, worked out so you don't have to. Good for one person in a hurry and for a family that can't agree.",
    items: [
      { n: "Mithaas Special Thali", d: "Two sabzi, dal, rice, four roti, raita, salad, papad and a sweet.", p: null, t: "signature", i: "special-thali" },
      { n: "Regular Veg Thali", d: "One sabzi, dal, rice, three roti, salad and papad.", p: null, i: "veg-thali" },
      { n: "Paneer Thali", d: "A paneer dish, dal, rice, roti, raita and a sweet.", p: null, i: "paneer-thali" },
      { n: "Mini Thali", d: "A smaller plate for a lighter afternoon.", p: null, i: "mini-thali" },
      { n: "Chole Bhature Combo", d: "Chole bhature with a cold drink and a piece of sweet.", p: null, i: "chole-bhature-combo" },
      { n: "Dosa Combo", d: "Masala dosa with a filter coffee.", p: null, i: "dosa-combo" },
      { n: "Family Pack", d: "Two sabzi, dal, rice, eight roti and raita. Serves four.", p: null, i: "family-pack" },
      { n: "Party Orders & Catering", d: "Bulk food for functions, offices and family events. Call to plan.", p: null, i: "catering" }
    ]
  },
  {
    id: "beverages",
    name: "Beverages",
    short: "Beverages",
    folder: "restaurant",
    note: "Hot & cold",
    intro: "Tea from the morning, shakes through the afternoon, and something cold for whoever has just walked in from the heat.",
    items: [
      { n: "Masala Chai", d: "Boiled properly, with ginger and cardamom.", v: ["Cup", "Kettle"], p: null, t: "signature", i: "masala-chai" },
      { n: "Filter Coffee", d: "South Indian filter decoction with hot milk.", p: null, i: "filter-coffee" },
      { n: "Cold Coffee", d: "Blended thick, with or without ice cream.", v: ["Plain", "With ice cream"], p: null, i: "cold-coffee" },
      { n: "Cappuccino", d: "Espresso and steamed milk.", p: null, i: "cappuccino" },
      { n: "Lassi", d: "Sweet, salted or mango. Served in a tall glass.", v: ["Sweet", "Salted", "Mango"], p: null, t: "signature", i: "lassi" },
      { n: "Chaas", d: "Thin buttermilk with roasted jeera and curry leaf.", p: null, i: "chaas" },
      { n: "Thandai", d: "Milk with almonds, fennel and saffron.", p: null, t: "seasonal", i: "thandai" },
      { n: "Milkshakes", d: "Mango, chocolate, strawberry, butterscotch, Oreo.", v: ["Mango", "Chocolate", "Strawberry", "Butterscotch", "Oreo"], p: null, i: "milkshake" },
      { n: "Fresh Lime", d: "Soda or water, sweet or salted.", v: ["Soda", "Water"], p: null, i: "fresh-lime" },
      { n: "Mojito", d: "Mint, lime and soda. Virgin.", v: ["Classic", "Green apple", "Blue"], p: null, i: "mojito" },
      { n: "Seasonal Juice", d: "Whatever fruit is good this week, pressed to order.", p: null, t: "seasonal", i: "juice" },
      { n: "Badam Milk", d: "Served hot in winter, chilled in summer.", p: null, i: "badam-milk" }
    ]
  },
  {
    id: "desserts",
    name: "Desserts",
    short: "Desserts",
    folder: "sweets",
    note: "To finish",
    intro: "For the end of the meal, or for the reason you came in.",
    items: [
      { n: "Gulab Jamun with Ice Cream", d: "Warm jamun, cold vanilla. The argument settles itself.", p: null, t: "signature", i: "jamun-ice-cream" },
      { n: "Rasmalai Bowl", d: "Two pieces in chilled saffron milk.", p: null, i: "rasmalai-bowl" },
      { n: "Falooda", d: "Rose syrup, vermicelli, basil seed and ice cream.", v: ["Rose", "Kesar", "Chocolate"], p: null, t: "signature", i: "falooda" },
      { n: "Kulfi", d: "Malai, pista or kesar, cut from the mould.", v: ["Malai", "Pista", "Kesar"], p: null, i: "kulfi" },
      { n: "Ice Cream", d: "Scoops or a family pack to take home.", v: ["Scoop", "Family pack"], p: null, i: "ice-cream" },
      { n: "Hot Gajar Halwa with Ice Cream", d: "Winter only, and worth waiting for.", p: null, t: "seasonal", i: "halwa-ice-cream" },
      { n: "Rabri Jalebi", d: "Hot jalebi under cold rabri.", p: null, t: "signature", i: "rabri-jalebi" },
      { n: "Fruit Cream", d: "Chopped seasonal fruit in sweetened cream.", p: null, i: "fruit-cream" },
      { n: "Sizzling Brownie", d: "Brownie, ice cream and hot chocolate sauce.", p: null, i: "sizzling-brownie" }
    ]
  }
];
