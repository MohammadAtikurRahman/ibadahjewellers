-- Optional starting catalogue from the existing storefront; there is no assumed inventory.
SET NAMES utf8mb4;

INSERT IGNORE INTO products
    (id, name, category, finish, finish_label, price, compare_price, image, tag, note, description, details, sort_order)
VALUES
    ('hoops', 'The Twist Hoops', 'Earrings', 'gold', 'Gold tone', 1250, 1500, 'hero-hoops.jpg', 'The everyday edit', 'A little golden hour.', 'Sculptural twists, a warm golden finish, and just the right amount of presence. Meet the hoops that make an ordinary day feel a little more considered.', 'Twisted hoop silhouette with a gold-tone finish. Decorative fashion jewellery. Product materials and dimensions should be confirmed with the store before a real purchase.', 10),
    ('pendant', 'Emerald Muse Pendant', 'Necklaces', 'gold', 'Gold tone · Green stone', 1850, 0, 'pendant.jpg', 'A touch of colour', 'Your own little statement.', 'A deep green geometric stone, framed in warm golden tones. A little colour to keep close, beautiful on its own or layered with your favourite fine chains.', 'Geometric pendant with a green decorative stone and gold-tone chain. The sample photograph illustrates the design; it does not certify gemstone type or metal purity.', 20),
    ('ring', 'The Halo Ring', 'Rings', 'silver', 'Silver tone · Clear stone', 1450, 1750, 'ring.jpg', 'A little occasion', 'A light-catching little detail.', 'A bright centre stone, a delicate halo, and a band with just a little extra sparkle. For the days you want your smallest details to shine.', 'Clear decorative centre stone with a halo setting and split shoulders. Silver-tone fashion jewellery. Choose your US size when ordering.', 30),
    ('layered', 'Golden Link Bracelet', 'Bracelets', 'gold', 'Gold tone', 1150, 0, 'layered.jpg', 'Keep it golden', 'A link to your everyday.', 'Open oval links and a warm golden finish. An uncomplicated favourite that adds a little character to a crisp shirt, soft knit or your everyday stack.', 'Oval-link chain bracelet with a gold-tone finish. The design is shown in a lifestyle photograph. Confirm actual length, fastening and materials before purchasing.', 40),
    ('pearl', 'Midnight Drop Earrings', 'Earrings', 'silver', 'Silver tone · Blue stone', 1650, 1950, 'pearl.jpg', 'The evening edit', 'For after-dark moments.', 'Deep blue centres framed with light-catching details. An expressive pair for an evening to remember, or simply a day that deserves a little drama.', 'Blue decorative stones in a silver-tone drop design, surrounded by clear stone details. The sample catalogue does not represent certified precious gemstones.', 50),
    ('bracelet', 'Rosé Link Bracelet', 'Bracelets', 'rose', 'Rose-gold tone', 1350, 0, 'bracelet.jpg', 'Softly, beautifully', 'A softer kind of sparkle.', 'Soft rose tones and delicate, light-catching links. A graceful little finishing touch, whether you wear it solo or beside a favourite watch.', 'Curved linked motifs with small decorative stones and a rose-gold-tone finish. Materials and fit are illustrative until confirmed by the store.', 60),
    ('keepsake', 'Pearl Keepsake Necklace', 'Necklaces', 'silver', 'Pearl style · Silver-tone detail', 2450, 2850, 'pearl-necklace.jpg', 'The keepsake edit', 'A little timeless wonder.', 'A pearl-style strand with an intricate silver-tone detail. An occasion piece with a quiet kind of beauty, for a gift or a little moment of your own.', 'White pearl-style beads and a decorative clasp, pictured in a jewellery box. The photograph does not establish pearl origin. Confirm length, materials and included packaging with the store.', 70),
    ('rose-ring', 'Rosé Halo Ring', 'Rings', 'gold', 'Gold tone · Pink-purple stone', 1750, 2100, 'additional-ring.jpg', 'A little colour story', 'For a moment all your own.', 'A rosy, rectangular centre framed by a delicate halo. Warm golden tones meet a little colour, in a ring that feels quietly special.', 'Rectangular pink-purple decorative stone with a clear halo and gold-tone band. Check the available US sizes before ordering.', 80);

INSERT IGNORE INTO variants (product_id, size, stock) VALUES
    ('hoops', 'One size', 0),
    ('pendant', 'One size', 0),
    ('ring', 'US 6', 0),
    ('ring', 'US 7', 0),
    ('ring', 'US 8', 0),
    ('layered', 'One size', 0),
    ('pearl', 'One size', 0),
    ('bracelet', 'One size', 0),
    ('keepsake', 'One size', 0),
    ('rose-ring', 'US 6', 0),
    ('rose-ring', 'US 7', 0),
    ('rose-ring', 'US 8', 0);
