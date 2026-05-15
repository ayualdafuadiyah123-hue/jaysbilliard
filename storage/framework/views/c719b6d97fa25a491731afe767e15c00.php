<div class="relative rounded-2xl overflow-hidden group cursor-pointer">
    
    <img src="<?php echo e(asset('storage/' . $promo->image)); ?>"
         alt="<?php echo e($promo->title); ?>"
         class="w-full aspect-video object-cover object-center group-hover:scale-105 transition duration-500"/>
    
    
    <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/40 to-transparent"></div>

    
    <div class="absolute inset-0 p-6 flex flex-col justify-between">
        <span class="self-start bg-[#00e5ff] text-black text-[10px] font-black uppercase
                     tracking-widest rounded-full px-3 py-1 shadow-[0_0_10px_rgba(0,229,255,0.5)]">
            <?php echo e($promo->badge); ?>

        </span>

        <div>
            <h3 class="text-white font-black text-3xl uppercase leading-tight mb-2">
                <?php echo e($promo->title); ?>

            </h3>
            <p class="text-gray-300 text-sm mb-4"><?php echo e($promo->description); ?></p>
            <div class="flex justify-end mt-4">
                <a href="<?php echo e($promo->cta_url ?? '#'); ?>"
                   class="inline-flex items-center gap-1 text-[#00e5ff] text-xs font-semibold
                           hover:text-white transition">
                    <?php echo e($promo->cta_text); ?> <span class="text-lg">→</span>
                </a>
            </div>
        </div>
    </div>
</div>
<?php /**PATH C:\laragon\www\jaysbilliard-main\resources\views/component/c_website/promo-card.blade.php ENDPATH**/ ?>